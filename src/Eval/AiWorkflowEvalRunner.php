<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Structured\Response as StructuredResponse;
use Throwable;

class AiWorkflowEvalRunner
{
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
                try {
                    $startedAt = hrtime(true);
                    $response = $this->replayer->replay($request, model: $model);
                    $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
                    $result = $judge->judge($request, $response);
                } catch (Throwable $e) {
                    Log::warning('AiWorkflow: Eval replay/judge failed', [
                        'request_id' => $request->id,
                        'model' => $model,
                        'error' => $e->getMessage(),
                    ]);

                    AiWorkflowEvalScore::create([
                        'eval_run_id' => $evalRun->id,
                        'request_id' => $request->id,
                        'model' => $model,
                        'score' => 0.0,
                        'details' => ['error' => $e->getMessage()],
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
    private function groundTruthFor(AiWorkflowRequest $request): ?string
    {
        $groundTruth = $request->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE);

        return is_string($groundTruth) && $groundTruth !== '' ? $groundTruth : null;
    }
}
