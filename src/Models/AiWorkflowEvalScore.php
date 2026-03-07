<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowEvalScore newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowEvalScore newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowEvalScore query()
 *
 * @property int $id
 * @property string $eval_run_id
 * @property int $request_id
 * @property string $model
 * @property float $score
 * @property array<string, mixed>|null $details
 * @property string|null $response_text
 * @property array<string, mixed>|null $structured_response
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \AiWorkflow\Models\AiWorkflowEvalRun $evalRun
 * @property-read \AiWorkflow\Models\AiWorkflowRequest $request
 *
 * @mixin \Eloquent
 */
class AiWorkflowEvalScore extends Model
{
    protected $table = 'ai_workflow_eval_scores';

    protected $fillable = [
        'eval_run_id',
        'request_id',
        'model',
        'score',
        'details',
        'response_text',
        'structured_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'float',
            'details' => 'array',
            'structured_response' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiWorkflowEvalRun, $this>
     */
    public function evalRun(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowEvalRun::class, 'eval_run_id');
    }

    /**
     * @return BelongsTo<AiWorkflowRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowRequest::class, 'request_id');
    }
}
