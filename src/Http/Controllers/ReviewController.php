<?php

declare(strict_types=1);

namespace AiWorkflow\Http\Controllers;

use AiWorkflow\Enums\AnnotationVerdict;
use AiWorkflow\Eval\ReviewContext;
use AiWorkflow\Eval\ReviewContextResolver;
use AiWorkflow\Eval\StructuredResponsePresenter;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;
use Throwable;

class ReviewController
{
    public function index(Request $request): View
    {
        $promptId = $this->stringFrom($request->query, 'prompt');
        $tag = $this->stringFrom($request->query, 'tag');
        $includeReviewed = filter_var($this->stringFrom($request->query, 'all') ?? '', FILTER_VALIDATE_BOOLEAN);

        $query = AiWorkflowRequest::query()
            // Deliberately narrow: `messages` carries the whole prompt, and for
            // multimodal calls that includes base64 attachments running to
            // megabytes each. Loading a page of those exhausts memory, so the
            // input is fetched per request from the `input` endpoint instead.
            // execution_id is needed by link resolvers, which use it to find
            // whatever record the host app filed this request under.
            ->select(['id', 'execution_id', 'prompt_id', 'provider', 'model', 'structured_response', 'duration_ms', 'created_at'])
            ->with('annotations')
            ->successful()
            ->latest('id');

        if ($promptId !== null) {
            $query->byPrompt($promptId);
        }

        if ($tag !== null) {
            $query->withTag($tag);
        }

        if (! $includeReviewed) {
            $query->whereDoesntHave('annotations');
        }

        $perPage = config('ai-workflow.review.per_page');
        $requests = $query->paginate(is_int($perPage) ? $perPage : 20)->withQueryString();

        return view('ai-workflow::review.index', [
            'requests' => $requests,
            'context' => $this->resolveContext(array_values($requests->items())),
            'promptId' => $promptId,
            'tag' => $tag,
            'includeReviewed' => $includeReviewed,
            'outstanding' => AiWorkflowRequest::query()->successful()->whereDoesntHave('annotations')->count(),
            'reviewed' => AiWorkflowAnnotation::query()->latestPerRequest()->count(),
        ]);
    }

    /**
     * The situation around each request, if the host app has told us how to
     * find it. A resolver that fails must not take the review page down with
     * it — labelling is the point, context is a convenience.
     *
     * @param  list<AiWorkflowRequest>  $requests
     * @return array<int, ReviewContext>
     */
    private function resolveContext(array $requests): array
    {
        $configured = config('ai-workflow.review.context');

        if (! is_string($configured) || $configured === '' || ! class_exists($configured)) {
            return [];
        }

        try {
            $resolver = app($configured);

            return $resolver instanceof ReviewContextResolver ? $resolver->resolve($requests) : [];
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The prompt text for a single request, fetched on demand so the list view
     * never has to hold every request's payload in memory at once.
     */
    public function input(AiWorkflowRequest $aiWorkflowRequest): HttpResponse
    {
        $text = '';

        foreach ($aiWorkflowRequest->messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $content = $message['content'] ?? null;

            if (is_string($content) && $content !== '') {
                $text = $content;
            }
        }

        if ($text === '') {
            $text = '(no text content — the prompt may be entirely media)';
        }

        // Even one request can be enormous once attachments are inlined.
        $limit = 200_000;
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit)."\n\n…truncated.";
        }

        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * @throws ValidationException
     */
    public function annotate(Request $request, AiWorkflowRequest $aiWorkflowRequest): RedirectResponse
    {
        $request->validate([
            'verdict' => ['required', Rule::enum(AnnotationVerdict::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $verdict = AnnotationVerdict::from($this->stringFrom($request->request, 'verdict') ?? '');

        AiWorkflowAnnotation::create([
            'request_id' => $aiWorkflowRequest->id,
            'verdict' => $verdict,
            'label' => $this->resolveLabel($request, $aiWorkflowRequest, $verdict),
            'reason' => $this->stringFrom($request->request, 'reason'),
            'reviewer' => $this->reviewer(),
        ]);

        return redirect()
            ->back()
            ->with('status', "Recorded {$verdict->value} for request #{$aiWorkflowRequest->id}.");
    }

    /**
     * The correct answer for this request, which is separate from whether the
     * model got it right.
     *
     * A rejection is worth far more when it says what should have happened —
     * that is both a recorded failure and an answer-key entry. But the answer
     * box is pre-filled with the model's own pick, so a rejection left
     * untouched would otherwise record that pick as correct and contradict
     * itself. An unedited box on a thumbs-down therefore means "wrong, and I am
     * not saying what was right".
     */
    private function resolveLabel(Request $request, AiWorkflowRequest $aiWorkflowRequest, AnnotationVerdict $verdict): ?string
    {
        $label = $this->stringFrom($request->request, 'label');

        if ($label === null || $verdict === AnnotationVerdict::Up) {
            return $label;
        }

        $structured = $aiWorkflowRequest->structured_response;
        $chosen = is_array($structured) ? StructuredResponsePresenter::topKey($structured) : null;

        return $label === $chosen ? null : $label;
    }

    private function reviewer(): ?string
    {
        $reviewer = config('ai-workflow.review.reviewer');

        return is_string($reviewer) && $reviewer !== '' ? $reviewer : null;
    }

    /**
     * @param  InputBag<string>  $bag
     */
    private function stringFrom(InputBag $bag, string $key): ?string
    {
        $value = $bag->get($key);

        return $value !== null && $value !== '' ? $value : null;
    }
}
