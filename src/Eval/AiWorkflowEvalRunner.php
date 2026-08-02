<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Response;
use Throwable;

class AiWorkflowEvalRunner
{
    /** Kept low: every attempt is a paid call, across every item in a run. */
    private const int DEFAULT_REPLAY_TRIES = 2;

    public function __construct(
        private readonly AiWorkflowReplayer $replayer,
    ) {}

    /**
     * Run an evaluation across one or more models using a judge.
     *
     * @param  list<AiWorkflowRequest>  $requests
     * @param  list<string>  $models  Each in provider:model format.
     * @param  array<string, mixed>  $config  Optional config metadata to store on the run.
     */
    public function run(
        string $name,
        array $requests,
        array $models,
        AiWorkflowEvalJudge $judge,
        array $config = [],
    ): AiWorkflowEvalRun {
        // Deliberately not wrapped in a transaction. A run is a long sequence of
        // paid network calls — hours, for a real golden set — and each score is
        // a result someone has already been billed for. Holding one transaction
        // across all of it means a timeout, a deploy or a crash discards work
        // that cannot be recovered without paying for it again, and keeps a
        // write transaction open on the database for the duration. Committing
        // as we go costs nothing and means an interrupted run keeps whatever it
        // finished.
        $evalRun = AiWorkflowEvalRun::create([
            'name' => $name,
            'models' => $models,
            'config' => $config !== [] ? $config : null,
        ]);

        foreach ($requests as $request) {
            foreach ($models as $model) {
                $response = null;
                $durationMs = null;

                try {
                    $startedAt = hrtime(true);
                    // Today's prompt, not the one recorded alongside the answer:
                    // editing a prompt and re-running the golden set is the
                    // regression test this framework exists for.
                    $response = $this->replay($request, $model);
                    $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
                    $result = $judge->judge($request, $response);
                } catch (Throwable $e) {
                    Log::warning('AiWorkflow: Eval replay/judge failed', [
                        'request_id' => $request->id,
                        'model' => $model,
                        'error' => $e->getMessage(),
                    ]);

                    // When the judge failed after a successful replay, the
                    // replay's tokens were still paid for: keep its usage on
                    // the error row, or the cost report undercounts the run by
                    // exactly the responses that were hardest to judge.
                    AiWorkflowEvalScore::create([
                        'eval_run_id' => $evalRun->id,
                        'request_id' => $request->id,
                        'model' => $model,
                        'score' => 0.0,
                        'details' => ['error' => $e->getMessage()],
                        'input_tokens' => $response?->usage->promptTokens,
                        'output_tokens' => $response?->usage->completionTokens,
                        'thought_tokens' => $response?->usage->thoughtTokens,
                        'duration_ms' => $durationMs,
                        'ground_truth' => $this->groundTruthFor($request),
                    ]);

                    continue;
                }

                // Persisted outside the try: only replay/judge failures are a
                // pair's own zero-score outcome. If saving a good (paid-for)
                // result fails, that must surface, not be rewritten as one.
                AiWorkflowEvalScore::create([
                    'eval_run_id' => $evalRun->id,
                    'request_id' => $request->id,
                    'model' => $model,
                    'score' => $result->score,
                    'details' => $result->details !== [] ? $result->details : null,
                    'response_text' => $response instanceof StructuredResponse ? null : $response->text,
                    'structured_response' => $response instanceof StructuredResponse ? $response->structured : null,
                    'input_tokens' => $response->usage->promptTokens,
                    'output_tokens' => $response->usage->completionTokens,
                    'thought_tokens' => $response->usage->thoughtTokens,
                    'duration_ms' => $durationMs,
                    'ground_truth' => $this->groundTruthFor($request),
                    'predicted' => $result->predicted,
                ]);
            }
        }

        return $evalRun->load('scores');
    }

    /**
     * The human-approved answer attached to this request by the golden-set
     * assembly, if any. Transient — it is never persisted on the request itself.
     */
    /**
     * Replay one request, retrying a transient failure.
     *
     * Generation is non-deterministic, so a response the provider could not
     * classify often succeeds on a second attempt. Scoring that as a wrong
     * answer would blame the model for a blip. A 4xx is the provider refusing
     * the request itself, which a retry only pays for again.
     */
    private function replay(AiWorkflowRequest $request, string $model): Response|StructuredResponse
    {
        $attempts = config('ai-workflow.eval.replay_tries');
        $attempts = is_int($attempts) && $attempts > 0 ? $attempts : self::DEFAULT_REPLAY_TRIES;

        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->replayer->replay($request, useCurrentPrompts: true, model: $model);
            } catch (Throwable $e) {
                if ($attempt >= $attempts || $this->isPermanent($e)) {
                    throw $e;
                }

                Log::warning('AiWorkflow: Eval replay failed, retrying', [
                    'request_id' => $request->id,
                    'model' => $model,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Whether retrying would fail the same way: the provider rejected the
     * request rather than fumbled the response.
     */
    private function isPermanent(Throwable $e): bool
    {
        $status = $e instanceof PrismException ? $e->httpStatus : null;

        return is_int($status) && $status >= 400 && $status < 500;
    }

    private function groundTruthFor(AiWorkflowRequest $request): ?string
    {
        $groundTruth = $request->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE);

        return is_string($groundTruth) && $groundTruth !== '' ? $groundTruth : null;
    }
}
