<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Eval\AiWorkflowEvalJudge;
use AiWorkflow\Eval\AiWorkflowEvalRunner;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Console\Command;

class EvalRunCommand extends Command
{
    /** @var string */
    protected $signature = 'ai-workflow:eval
        {--judge= : FQCN of the judge class (must implement AiWorkflowEvalJudge)}
        {--tag= : Filter requests by tag}
        {--prompt= : Filter requests by prompt ID}
        {--models= : Comma-separated list of models in provider:model format}
        {--name= : Name for the eval run}
        {--limit=100 : Maximum number of requests to evaluate}';

    /** @var string */
    protected $description = 'Run an evaluation across recorded AI requests using different models and a judge.';

    public function handle(AiWorkflowEvalRunner $runner): int
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

        $requests = $this->loadRequests();
        if ($requests === []) {
            $this->warn('No requests found matching the criteria.');

            return self::SUCCESS;
        }

        $name = $this->resolveRunName();
        $this->info("Running eval '{$name}' with ".count($requests).' request(s) across '.count($models).' model(s)...');

        $evalRun = $runner->run($name, $requests, $models, $judge);

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

    /**
     * @return list<AiWorkflowRequest>
     */
    private function loadRequests(): array
    {
        $query = AiWorkflowRequest::query();

        $tag = $this->option('tag');
        if (is_string($tag) && $tag !== '') {
            $query->withTag($tag);
        }

        $promptId = $this->option('prompt');
        if (is_string($promptId) && $promptId !== '') {
            $query->byPrompt($promptId);
        }

        $limit = (int) $this->option('limit');

        return array_values($query->orderBy('id')->limit($limit)->get()->all());
    }

    private function resolveRunName(): string
    {
        $name = $this->option('name');

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $parts = ['Eval'];

        $tag = $this->option('tag');
        if (is_string($tag) && $tag !== '') {
            $parts[] = "tag:{$tag}";
        }

        $prompt = $this->option('prompt');
        if (is_string($prompt) && $prompt !== '') {
            $parts[] = "prompt:{$prompt}";
        }

        $parts[] = now()->format('Y-m-d H:i');

        return implode(' ', $parts);
    }
}
