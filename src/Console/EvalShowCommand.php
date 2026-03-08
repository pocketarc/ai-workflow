<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Models\AiWorkflowEvalDataset;
use Illuminate\Console\Command;

class EvalShowCommand extends Command
{
    /** @var string */
    protected $signature = 'eval:show {name : The dataset name}';

    /** @var string */
    protected $description = 'Show executions in an eval dataset.';

    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name');

        $dataset = AiWorkflowEvalDataset::query()->where('name', $name)->first();

        if ($dataset === null) {
            $this->error("Dataset '{$name}' not found.");

            return self::FAILURE;
        }

        $entries = $dataset->entries()->with('execution.requests')->get();

        if ($entries->isEmpty()) {
            $this->warn("Dataset '{$name}' has no executions.");

            return self::SUCCESS;
        }

        $this->info("Dataset: {$name}");
        $this->newLine();

        $rows = $entries->map(function ($entry): array {
            $execution = $entry->execution;
            $requestCount = $execution->requests->count();
            $models = $execution->requests->pluck('model')->unique()->implode(', ');

            return [
                $execution->id,
                $execution->name,
                (string) $requestCount,
                $models,
                $execution->created_at?->format('Y-m-d H:i') ?? '',
            ];
        })->all();

        $this->table(['Execution ID', 'Name', 'Requests', 'Models', 'Created'], $rows);

        return self::SUCCESS;
    }
}
