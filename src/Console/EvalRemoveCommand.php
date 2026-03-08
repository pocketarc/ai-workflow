<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Models\AiWorkflowEvalDataset;
use AiWorkflow\Models\AiWorkflowEvalDatasetEntry;
use Illuminate\Console\Command;

class EvalRemoveCommand extends Command
{
    /** @var string */
    protected $signature = 'eval:remove
        {name : The dataset name}
        {executionId : The execution ID to remove}';

    /** @var string */
    protected $description = 'Remove an execution from an eval dataset.';

    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name');
        /** @var string $executionId */
        $executionId = $this->argument('executionId');

        $dataset = AiWorkflowEvalDataset::query()->where('name', $name)->first();

        if ($dataset === null) {
            $this->error("Dataset '{$name}' not found.");

            return self::FAILURE;
        }

        $deleted = AiWorkflowEvalDatasetEntry::query()
            ->where('dataset_id', $dataset->id)
            ->where('execution_id', $executionId)
            ->delete();

        if ($deleted === 0) {
            $this->warn("Execution '{$executionId}' is not in dataset '{$name}'.");

            return self::SUCCESS;
        }

        $this->info("Removed execution '{$executionId}' from dataset '{$name}'.");

        if ($dataset->entries()->count() === 0) {
            $dataset->delete();
            $this->info("Dataset '{$name}' is now empty and has been deleted.");
        }

        return self::SUCCESS;
    }
}
