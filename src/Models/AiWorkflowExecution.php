<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use AiWorkflow\Models\Builders\AiWorkflowExecutionBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @method static AiWorkflowExecutionBuilder<AiWorkflowExecution> newModelQuery()
 * @method static AiWorkflowExecutionBuilder<AiWorkflowExecution> newQuery()
 * @method static AiWorkflowExecutionBuilder<AiWorkflowExecution> query()
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \AiWorkflow\Models\AiWorkflowRequest> $requests
 * @property-read int|null $requests_count
 *
 * @mixin \Eloquent
 */
class AiWorkflowExecution extends Model
{
    use HasUuids;

    protected $table = 'ai_workflow_executions';

    protected $fillable = [
        'name',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return AiWorkflowExecutionBuilder<AiWorkflowExecution>
     */
    #[Override]
    public function newEloquentBuilder($query): AiWorkflowExecutionBuilder
    {
        return new AiWorkflowExecutionBuilder($query);
    }

    /**
     * @return HasMany<AiWorkflowRequest, $this>
     */
    public function requests(): HasMany
    {
        return $this->hasMany(AiWorkflowRequest::class, 'execution_id');
    }

    public function totalInputTokens(): int
    {
        return (int) $this->requests()->sum('input_tokens');
    }

    public function totalOutputTokens(): int
    {
        return (int) $this->requests()->sum('output_tokens');
    }

    public function totalTokens(): int
    {
        return $this->totalInputTokens() + $this->totalOutputTokens();
    }

    public function totalDurationMs(): int
    {
        return (int) $this->requests()->sum('duration_ms');
    }

    public function requestCount(): int
    {
        return $this->requests()->count();
    }
}
