<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $dataset_id
 * @property string $execution_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiWorkflowEvalDataset $dataset
 * @property-read AiWorkflowExecution $execution
 *
 * @mixin \Eloquent
 */
class AiWorkflowEvalDatasetEntry extends Model
{
    protected $table = 'ai_workflow_eval_dataset_entries';

    protected $fillable = [
        'dataset_id',
        'execution_id',
    ];

    /**
     * @return BelongsTo<AiWorkflowEvalDataset, $this>
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowEvalDataset::class, 'dataset_id');
    }

    /**
     * @return BelongsTo<AiWorkflowExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowExecution::class, 'execution_id');
    }
}
