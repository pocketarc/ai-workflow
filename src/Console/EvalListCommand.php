<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Models\AiWorkflowEvalDataset;
use AiWorkflow\Models\AiWorkflowRequest;
use Illuminate\Console\Command;

class EvalListCommand extends Command
{
    /** @var string */
    protected $signature = 'eval:list';

    /** @var string */
    protected $description = 'List all eval datasets.';

    public function handle(): int
    {
        $datasets = AiWorkflowEvalDataset::query()
            ->withCount('entries')
            ->orderBy('name')
            ->get();

        if ($datasets->isEmpty()) {
            $this->info('No datasets found. Create one with: eval:add {name} {executionId}');

            return self::SUCCESS;
        }

        $rows = $datasets->map(function (AiWorkflowEvalDataset $dataset): array {
            $executionIds = $dataset->entries()->pluck('execution_id');
            $requestCount = $executionIds->isNotEmpty()
                ? AiWorkflowRequest::query()->whereIn('execution_id', $executionIds)->count()
                : 0;

            return [
                $dataset->name,
                (string) $dataset->entries_count,
                (string) $requestCount,
                $dataset->created_at?->format('Y-m-d H:i') ?? '',
            ];
        })->all();

        $this->table(['Dataset', 'Executions', 'Requests', 'Created'], $rows);

        return self::SUCCESS;
    }
}
