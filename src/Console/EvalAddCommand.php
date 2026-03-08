<?php

declare(strict_types=1);

namespace AiWorkflow\Console;

use AiWorkflow\Models\AiWorkflowEvalDataset;
use AiWorkflow\Models\AiWorkflowEvalDatasetEntry;
use AiWorkflow\Models\AiWorkflowExecution;
use Illuminate\Console\Command;

class EvalAddCommand extends Command
{
    /** @var string */
    protected $signature = 'eval:add
        {name : The dataset name}
        {executionId : The execution ID to add}';

    /** @var string */
    protected $description = 'Add an execution to an eval dataset.';

    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name');
        /** @var string $executionId */
        $executionId = $this->argument('executionId');

        $execution = AiWorkflowExecution::query()->find($executionId);

        if (! $execution instanceof AiWorkflowExecution) {
            $this->error("Execution '{$executionId}' not found.");

            return self::FAILURE;
        }

        $dataset = AiWorkflowEvalDataset::query()->firstOrCreate(['name' => $name]);

        $exists = AiWorkflowEvalDatasetEntry::query()
            ->where('dataset_id', $dataset->id)
            ->where('execution_id', $execution->id)
            ->exists();

        if ($exists) {
            $this->warn("Execution '{$executionId}' is already in dataset '{$name}'.");

            return self::SUCCESS;
        }

        AiWorkflowEvalDatasetEntry::create([
            'dataset_id' => $dataset->id,
            'execution_id' => $execution->id,
        ]);

        $requestCount = $execution->requests()->count();

        $this->info("Added execution '{$execution->name}' ({$requestCount} request(s)) to dataset '{$name}'.");

        return self::SUCCESS;
    }
}
