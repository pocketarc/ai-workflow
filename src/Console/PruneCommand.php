<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Models\AiWorkflowEvalDatasetEntry;
use AiWorkflow\Models\AiWorkflowExecution;
use AiWorkflow\Models\AiWorkflowRequest;
use AiWorkflow\Models\Builders\AiWorkflowExecutionBuilder;
use AiWorkflow\Models\Builders\AiWorkflowRequestBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'ai-workflow:prune';

    /** @var string */
    protected $description = 'Delete AI workflow requests and empty executions past the retention window.';

    public function handle(): int
    {
        $days = $this->positiveIntConfig('ai-workflow.pruning.requests_days');
        $chunkSize = $this->positiveIntConfig('ai-workflow.pruning.chunk_size');

        $requestsPruned = $this->pruneRequests($days, $chunkSize);
        $executionsPruned = $this->pruneExecutions($days, $chunkSize);

        $this->info("Pruned {$requestsPruned} request(s) older than {$days} days.");
        $this->info("Pruned {$executionsPruned} empty execution(s) older than {$days} days.");

        return self::SUCCESS;
    }

    /**
     * A misconfigured value must stop the command, not be silently replaced:
     * this command deletes data, and a negative window would put the cutoff in
     * the future and take everything unreferenced with it.
     */
    private function positiveIntConfig(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value <= 0) {
            throw new RuntimeException(
                sprintf('Invalid %s (%s): must be a positive integer.', $key, var_export($value, true)),
            );
        }

        return $value;
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
            $ids = $this->prunableRequests($cutoff)
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // The delete re-applies the guards rather than trusting the ids: a
            // request picked by the pluck could gain an annotation or score
            // before the delete runs, and the cascade would silently take that
            // fresh eval data with it.
            $deleted = $this->prunableRequests($cutoff)->whereIn('id', $ids)->delete();
            $totalDeleted += is_int($deleted) ? $deleted : 0;
        } while ($ids->count() >= $chunkSize);

        return $totalDeleted;
    }

    /**
     * @return AiWorkflowRequestBuilder<AiWorkflowRequest>
     */
    private function prunableRequests(Carbon $cutoff): AiWorkflowRequestBuilder
    {
        return AiWorkflowRequest::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('annotations')
            ->whereDoesntHave('evalScores')
            ->where(function (AiWorkflowRequestBuilder $query): void {
                $query->whereNull('execution_id')
                    ->orWhereNotIn('execution_id', AiWorkflowEvalDatasetEntry::query()->select('execution_id'));
            });
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
            $ids = $this->prunableExecutions($cutoff)
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // Same re-check as the request delete: an execution picked by the
            // pluck could gain a request or a dataset entry before the delete
            // runs.
            $deleted = $this->prunableExecutions($cutoff)->whereIn('id', $ids)->delete();
            $totalDeleted += is_int($deleted) ? $deleted : 0;
        } while ($ids->count() >= $chunkSize);

        return $totalDeleted;
    }

    /**
     * @return AiWorkflowExecutionBuilder<AiWorkflowExecution>
     */
    private function prunableExecutions(Carbon $cutoff): AiWorkflowExecutionBuilder
    {
        return AiWorkflowExecution::query()
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('requests')
            ->whereNotIn('id', AiWorkflowEvalDatasetEntry::query()->select('execution_id'));
    }
}
