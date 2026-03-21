<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AiWorkflowEvalDatasetEntry> $entries
 * @property-read int|null $entries_count
 *
 * @mixin \Eloquent
 */
class AiWorkflowEvalDataset extends Model
{
    use HasUuids;

    protected $table = 'ai_workflow_eval_datasets';

    protected $fillable = [
        'name',
    ];

    /**
     * @return HasMany<AiWorkflowEvalDatasetEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(AiWorkflowEvalDatasetEntry::class, 'dataset_id');
    }

    /**
     * Load all requests across all executions in this dataset.
     *
     * @return list<AiWorkflowRequest>
     */
    public function requests(): array
    {
        $executionIds = $this->entries()->pluck('execution_id');

        return array_values(
            AiWorkflowRequest::query()
                ->whereIn('execution_id', $executionIds)
                ->orderBy('id')
                ->get()
                ->all()
        );
    }
}
