<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use AiWorkflow\AiWorkflowReplayer;
use AiWorkflow\Models\AiWorkflowEvalRun;
use AiWorkflow\Models\AiWorkflowEvalScore;
use AiWorkflow\Models\AiWorkflowRequest;
use Prism\Prism\Structured\Response as StructuredResponse;

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
        $evalRun = AiWorkflowEvalRun::create([
            'name' => $name,
            'models' => $models,
            'config' => $config !== [] ? $config : null,
        ]);

        foreach ($requests as $request) {
            foreach ($models as $model) {
                $response = $this->replayer->replay($request, model: $model);
                $result = $judge->judge($request, $response);

                AiWorkflowEvalScore::create([
                    'eval_run_id' => $evalRun->id,
                    'request_id' => $request->id,
                    'model' => $model,
                    'score' => $result->score,
                    'details' => $result->details !== [] ? $result->details : null,
                    'response_text' => $response instanceof StructuredResponse ? null : $response->text,
                    'structured_response' => $response instanceof StructuredResponse ? $response->structured : null,
                    'created_at' => now(),
                ]);
            }
        }

        return $evalRun->load('scores');
    }
}
