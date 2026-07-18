<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Models\AiWorkflowEvalDatasetEntry;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Models\Builders\AiWorkflowRequestBuilder;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'ai-workflow:prune';

    /** @var string */
    protected $description = 'Delete AI workflow requests and empty executions past the retention window.';

    public function handle(): int
    {
        $daysConfig = config('ai-workflow.pruning.requests_days');
        $days = is_int($daysConfig) ? $daysConfig : 90;

        $chunkConfig = config('ai-workflow.pruning.chunk_size');
        $chunkSize = is_int($chunkConfig) ? $chunkConfig : 1000;

        $requestsPruned = $this->pruneRequests($days, $chunkSize);
        $executionsPruned = $this->pruneExecutions($days, $chunkSize);

        $this->info("Pruned {$requestsPruned} request(s) older than {$days} days.");
        $this->info("Pruned {$executionsPruned} empty execution(s) older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * Delete requests past the retention window, except those the eval
     * framework references. Annotations and eval scores cascade-delete with
     * their request, and dataset replays re-run every request under the
     * dataset's executions — so an annotated, scored, or dataset-member
     * request lives as long as the eval data pointing at it.
     */
    private function pruneRequests(int $days, int $chunkSize): int
    {
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            $ids = AiWorkflowRequest::query()
                ->where('created_at', '<', $cutoff)
                ->whereDoesntHave('annotations')
                ->whereDoesntHave('evalScores')
                ->where(function (AiWorkflowRequestBuilder $query): void {
                    $query->whereNull('execution_id')
                        ->orWhereNotIn('execution_id', AiWorkflowEvalDatasetEntry::query()->select('execution_id'));
                })
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = AiWorkflowRequest::query()->whereIn('id', $ids)->delete();
            $totalDeleted += is_int($deleted) ? $deleted : 0;
        } while ($ids->count() >= $chunkSize);

        return $totalDeleted;
    }

    /**
     * An execution is a grouping row; it is prunable once it has no requests
     * left. Dataset membership still pins it, because deleting the execution
     * would cascade away the dataset entry itself.
     */
    private function pruneExecutions(int $days, int $chunkSize): int
    {
        $cutoff = now()->subDays($days);
        $totalDeleted = 0;

        do {
            $ids = AiWorkflowExecution::query()
                ->where('created_at', '<', $cutoff)
                ->whereDoesntHave('requests')
                ->whereNotIn('id', AiWorkflowEvalDatasetEntry::query()->select('execution_id'))
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted = AiWorkflowExecution::query()->whereIn('id', $ids)->delete();
            $totalDeleted += is_int($deleted) ? $deleted : 0;
        } while ($ids->count() >= $chunkSize);

        return $totalDeleted;
    }
}
