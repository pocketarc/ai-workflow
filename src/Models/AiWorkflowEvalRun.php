<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowEvalRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowEvalRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowEvalRun query()
 *
 * @property string $id
 * @property string $name
 * @property list<string> $models
 * @property array<string, mixed>|null $config
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \AiWorkflow\Models\AiWorkflowEvalScore> $scores
 * @property-read int|null $scores_count
 *
 * @mixin \Eloquent
 */
class AiWorkflowEvalRun extends Model
{
    use HasUuids;

    protected $table = 'ai_workflow_eval_runs';

    protected $fillable = [
        'name',
        'models',
        'config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'models' => 'array',
            'config' => 'array',
        ];
    }

    /**
     * @return HasMany<AiWorkflowEvalScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(AiWorkflowEvalScore::class, 'eval_run_id');
    }

    /**
     * Get the average score across all evaluations in this run.
     */
    public function averageScore(): float
    {
        return (float) $this->scores()->avg('score');
    }

    /**
     * Get the average score for a specific model.
     */
    public function averageScoreForModel(string $model): float
    {
        return (float) $this->scores()->where('model', $model)->avg('score');
    }
}
