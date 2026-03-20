<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use AiWorkflow\Models\Builders\AiWorkflowExecutionBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;
use stdClass;

/**
 * @method static AiWorkflowExecutionBuilder<AiWorkflowExecution> newModelQuery()
 * @method static AiWorkflowExecutionBuilder<AiWorkflowExecution> newQuery()
 * @method static AiWorkflowExecutionBuilder<AiWorkflowExecution> query()
 *
 * @property string $id
 * @property string $name
 * @property array<string, mixed>|null $metadata
 * @property-read stdClass $request_stats
 * @property-read int $total_input_tokens
 * @property-read int $total_output_tokens
 * @property-read int $total_thought_tokens
 * @property-read int $total_tokens
 * @property-read int $total_duration_ms
 * @property-read int $request_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \AiWorkflow\Models\AiWorkflowRequest> $requests
 * @property-read int|null $requests_count
 *
 * @method static AiWorkflowExecutionBuilder<static>|AiWorkflowExecution byName(string $name)
 * @method static AiWorkflowExecutionBuilder<static>|AiWorkflowExecution recent(int $hours = 24)
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

    /**
     * @return Attribute<stdClass, never>
     */
    protected function requestStats(): Attribute
    {
        return Attribute::make(get: fn (): stdClass => $this->requests()
            ->toBase()
            ->selectRaw('COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output, COALESCE(SUM(thought_tokens), 0) as total_thought, COALESCE(SUM(duration_ms), 0) as total_duration, COUNT(*) as total_count')
            ->first() ?? (object) ['total_input' => 0, 'total_output' => 0, 'total_thought' => 0, 'total_duration' => 0, 'total_count' => 0]
        );
    }

    /**
     * @return Attribute<int, never>
     */
    protected function totalInputTokens(): Attribute
    {
        return Attribute::make(get: function (): int {
            $value = $this->request_stats->total_input;

            return is_numeric($value) ? (int) $value : 0;
        });
    }

    /**
     * @return Attribute<int, never>
     */
    protected function totalOutputTokens(): Attribute
    {
        return Attribute::make(get: function (): int {
            $value = $this->request_stats->total_output;

            return is_numeric($value) ? (int) $value : 0;
        });
    }

    /**
     * @return Attribute<int, never>
     */
    protected function totalThoughtTokens(): Attribute
    {
        return Attribute::make(get: function (): int {
            $value = $this->request_stats->total_thought;

            return is_numeric($value) ? (int) $value : 0;
        });
    }

    /**
     * @return Attribute<int, never>
     */
    protected function totalTokens(): Attribute
    {
        return Attribute::make(get: fn (): int => $this->total_input_tokens + $this->total_output_tokens);
    }

    /**
     * @return Attribute<int, never>
     */
    protected function totalDurationMs(): Attribute
    {
        return Attribute::make(get: function (): int {
            $value = $this->request_stats->total_duration;

            return is_numeric($value) ? (int) $value : 0;
        });
    }

    /**
     * @return Attribute<int, never>
     */
    protected function requestCount(): Attribute
    {
        return Attribute::make(get: function (): int {
            $value = $this->request_stats->total_count;

            return is_numeric($value) ? (int) $value : 0;
        });
    }
}
