<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Models\AiWorkflowEvalDataset;
use Illuminate\Console\Command;

class EvalRunCommand extends Command
{
    /** @var string */
    protected $signature = 'eval:run
        {name : The dataset name to evaluate}
        {--judge= : FQCN of the judge class (must implement AiWorkflowEvalJudge)}
        {--models= : Comma-separated list of models in provider:model format}
        {--run-name= : Name for the eval run (defaults to dataset name + timestamp)}';

    /** @var string */
    protected $description = 'Run an evaluation against a dataset using different models and a judge.';

    public function handle(AiWorkflowEvalRunner $runner): int
    {
        /** @var string $datasetName */
        $datasetName = $this->argument('name');

        $dataset = AiWorkflowEvalDataset::query()->where('name', $datasetName)->first();
        if ($dataset === null) {
            $this->error("Dataset '{$datasetName}' not found.");

            return self::FAILURE;
        }

        $judge = $this->resolveJudge();
        if ($judge === null) {
            return self::FAILURE;
        }

        $models = $this->parseModels();
        if ($models === []) {
            $this->error('At least one model is required. Use --models=provider:model');

            return self::FAILURE;
        }

        $requests = $dataset->requests();
        if ($requests === []) {
            $this->warn("Dataset '{$datasetName}' has no requests.");

            return self::SUCCESS;
        }

        $runName = $this->resolveRunName($datasetName);
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

    private function resolveRunName(string $datasetName): string
    {
        $name = $this->option('run-name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $datasetName.' '.now()->format('Y-m-d H:i');
    }
}
