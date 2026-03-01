<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiWorkflowRequest query()
 *
 * @property int $id
 * @property string|null $execution_id
 * @property string $prompt_id
 * @property string $method
 * @property string $provider
 * @property string $model
 * @property string $system_prompt
 * @property array<int, mixed> $messages
 * @property string|null $response_text
 * @property array<string, mixed>|null $structured_response
 * @property string|null $finish_reason
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int $duration_ms
 * @property array<string, mixed>|null $schema
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \AiWorkflow\Models\AiWorkflowExecution|null $execution
 *
 * @mixin \Eloquent
 */
class AiWorkflowRequest extends Model
{
    public $timestamps = false;

    protected $table = 'ai_workflow_requests';

    protected $fillable = [
        'execution_id',
        'prompt_id',
        'method',
        'provider',
        'model',
        'system_prompt',
        'messages',
        'response_text',
        'structured_response',
        'finish_reason',
        'input_tokens',
        'output_tokens',
        'duration_ms',
        'schema',
        'error',
        'metadata',
	    'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'structured_response' => 'array',
            'schema' => 'array',
            'metadata' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiWorkflowExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowExecution::class, 'execution_id');
    }
}
