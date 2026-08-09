<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Eval\GoldenSetAssembler;
use AiWorkflow\Models\AiWorkflowEvalDataset;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Console\Command;

class EvalRunCommand extends Command
{
    /** @var string */
    protected $signature = 'eval:run
        {name? : The dataset name to evaluate (omit when using --from-annotations)}
        {--judge= : FQCN of the judge class (must implement AiWorkflowEvalJudge)}
        {--models= : Comma-separated list of models in provider:model format}
        {--run-name= : Name for the eval run (defaults to the source name + timestamp)}
        {--from-annotations : Build the request list from human-labelled requests instead of a dataset}
        {--prompt= : With --from-annotations, restrict to this prompt id}
        {--corrections : With --from-annotations, keep only the requests whose answer differs from the recorded pick}
        {--limit= : With --from-annotations, cap the number of requests}';

    /** @var string */
    protected $description = 'Run an evaluation against a dataset using different models and a judge.';

    public function handle(AiWorkflowEvalRunner $runner, GoldenSetAssembler $assembler): int
    {
        $judge = $this->resolveJudge();
        if ($judge === null) {
            return self::FAILURE;
        }

        $models = $this->parseModels();
        if ($models === []) {
            $this->error('At least one model is required. Use --models=provider:model');

            return self::FAILURE;
        }

        $source = $this->resolveRequests($assembler);
        if ($source === null) {
            return self::FAILURE;
        }

        ['label' => $sourceLabel, 'requests' => $requests] = $source;

        if ($requests === []) {
            $this->warn("No requests found for '{$sourceLabel}'.");

            return self::SUCCESS;
        }

        $this->warnAboutUnlabelledRequests($requests);

        $runName = $this->resolveRunName($sourceLabel);
        $this->info("Running eval '{$runName}' with ".count($requests).' request(s) across '.count($models).' model(s)...');

        $evalRun = $runner->run($runName, $requests, $models, $judge);

        $this->newLine();
        $this->info("Eval run complete: {$evalRun->id}");
        $this->info("Overall average score: {$evalRun->averageScore()}");

        $this->newLine();
        $this->table(
            ['Model', 'Avg Score', 'Scores'],
            collect($models)->map(fn (string $model): array => [
                $model,
                number_format($evalRun->averageScoreForModel($model), 4),
                (string) $evalRun->scores()->where('model', $model)->count(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return array{label: string, requests: list<AiWorkflowRequest>}|null
     */
    private function resolveRequests(GoldenSetAssembler $assembler): ?array
    {
        if ($this->option('from-annotations') === true) {
            return $this->resolveGoldenSet($assembler);
        }

        $datasetName = $this->argument('name');
        if (! is_string($datasetName) || $datasetName === '') {
            $this->error('A dataset name is required, or use --from-annotations to build the set from review answers.');

            return null;
        }

        $dataset = AiWorkflowEvalDataset::query()->where('name', $datasetName)->first();
        if ($dataset === null) {
            $this->error("Dataset '{$datasetName}' not found.");

            return null;
        }

        return ['label' => $datasetName, 'requests' => $dataset->requests()];
    }

    /**
     * @return array{label: string, requests: list<AiWorkflowRequest>}|null
     */
    private function resolveGoldenSet(GoldenSetAssembler $assembler): ?array
    {
        $correctionsOnly = $this->option('corrections') === true;

        $promptOption = $this->option('prompt');
        $promptId = is_string($promptOption) && $promptOption !== '' ? $promptOption : null;

        $limit = $this->parseLimit();
        if ($limit === false) {
            return null;
        }

        return [
            'label' => 'golden:'.($promptId ?? 'all').($correctionsOnly ? ':corrections' : ''),
            'requests' => $assembler->assemble($promptId, $correctionsOnly, $limit),
        ];
    }

    /**
     * @return int|null|false Null for "no limit", false when the option is invalid.
     */
    private function parseLimit(): int|null|false
    {
        $raw = $this->option('limit');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        if (! ctype_digit($raw) || (int) $raw < 1) {
            $this->error("--limit must be a positive integer, got '{$raw}'.");

            return false;
        }

        return (int) $raw;
    }

    /**
     * A request without a label still runs, but it is scored against the
     * recorded response rather than a human answer — so say so rather than
     * letting it quietly weaken the eval.
     *
     * @param  list<AiWorkflowRequest>  $requests
     */
    private function warnAboutUnlabelledRequests(array $requests): void
    {
        $unlabelled = 0;

        foreach ($requests as $request) {
            $groundTruth = $request->getAttribute(AiWorkflowRequest::GROUND_TRUTH_ATTRIBUTE);

            if (! is_string($groundTruth) || $groundTruth === '') {
                $unlabelled++;
            }
        }

        if ($unlabelled > 0) {
            $this->warn("{$unlabelled} of ".count($requests).' request(s) have no ground-truth label; those fall back to the judge default.');
        }
    }

    private function resolveJudge(): ?AiWorkflowEvalJudge
    {
        $judgeClass = $this->option('judge');
        if (! is_string($judgeClass) || $judgeClass === '') {
            $this->error('A judge class is required. Use --judge=App\\Eval\\MyJudge');

            return null;
        }

        if (! class_exists($judgeClass)) {
            $this->error("Judge class '{$judgeClass}' not found.");

            return null;
        }

        $judge = app($judgeClass);
        if (! $judge instanceof AiWorkflowEvalJudge) {
            $this->error("Class '{$judgeClass}' does not implement AiWorkflowEvalJudge.");

            return null;
        }

        return $judge;
    }

    /**
     * @return list<string>
     */
    private function parseModels(): array
    {
        $modelsOption = $this->option('models');
        if (! is_string($modelsOption) || $modelsOption === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $modelsOption)), static fn (string $v): bool => $v !== ''));
    }

    private function resolveRunName(string $sourceLabel): string
    {
        $name = $this->option('run-name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $sourceLabel.' '.now()->format('Y-m-d H:i');
    }
}
