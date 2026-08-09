<?php

declare(strict_types=1);

namespace AiWorkflow\Http\Controllers;

use AiWorkflow\Eval\ReviewContextLookup;
use AiWorkflow\Models\AiWorkflowAnnotation;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;

class ReviewController
{
    public function __construct(
        private readonly ReviewContextLookup $context,
    ) {}

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
            ->select(['id', 'execution_id', 'prompt_id', 'provider', 'model', 'response_text', 'structured_response', 'duration_ms', 'created_at'])
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
            'context' => $this->context->for(array_values($requests->items())),
            'promptId' => $promptId,
            'tag' => $tag,
            'includeReviewed' => $includeReviewed,
            'outstanding' => AiWorkflowRequest::query()->successful()->whereDoesntHave('annotations')->count(),
            'reviewed' => AiWorkflowAnnotation::query()->latestPerRequest()->count(),
        ]);
    }

    /**
     * The prompt text for a single request, fetched on demand so the list view
     * never has to hold every request's payload in memory at once.
     */
    public function input(AiWorkflowRequest $aiWorkflowRequest): HttpResponse
    {
        $parts = [];

        foreach ($aiWorkflowRequest->messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $content = $message['content'] ?? null;

            if (is_string($content) && $content !== '') {
                $parts[] = $content;
            }
        }

        // The reviewer is judging what the model saw, and on a multi-turn
        // request that includes the earlier messages, not just the last one.
        $text = implode("\n\n", $parts);

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
            'label' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $label = $this->stringFrom($request->request, 'label');

        AiWorkflowAnnotation::create([
            'request_id' => $aiWorkflowRequest->id,
            'label' => $label,
            'reason' => $this->stringFrom($request->request, 'reason'),
            'reviewer' => $this->reviewer(),
        ]);

        // An empty box is a review too: it records that the request was looked
        // at and no answer was settled on, which keeps it out of the queue
        // without putting a guess into the answer key.
        $status = $label === null
            ? "Recorded no answer for request #{$aiWorkflowRequest->id}."
            : "Recorded {$label} for request #{$aiWorkflowRequest->id}.";

        return redirect()->back()->with('status', $status);
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
