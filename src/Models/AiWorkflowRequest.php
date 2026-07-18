<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use AiWorkflow\Models\Builders\AiWorkflowRequestBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Override;

/**
 * @method static AiWorkflowRequestBuilder<AiWorkflowRequest> newModelQuery()
 * @method static AiWorkflowRequestBuilder<AiWorkflowRequest> newQuery()
 * @method static AiWorkflowRequestBuilder<AiWorkflowRequest> query()
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
 * @property int|null $thought_tokens
 * @property int $duration_ms
 * @property array<string, mixed>|null $schema
 * @property string|null $error
 * @property int|null $http_status
 * @property string|null $response_body
 * @property string|null $error_class
 * @property array<string, mixed>|null $metadata
 * @property list<string>|null $tags
 * @property array<string, mixed>|null $template_variables
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiWorkflowExecution|null $execution
 * @property-read Collection<int, AiWorkflowAnnotation> $annotations
 *
 * @method static AiWorkflowRequestBuilder<static>|AiWorkflowRequest byModel(string $model)
 * @method static AiWorkflowRequestBuilder<static>|AiWorkflowRequest byPrompt(string $promptId)
 * @method static AiWorkflowRequestBuilder<static>|AiWorkflowRequest errors()
 * @method static AiWorkflowRequestBuilder<static>|AiWorkflowRequest successful()
 * @method static AiWorkflowRequestBuilder<static>|AiWorkflowRequest withAnyTag(list<string> $tags)
 * @method static AiWorkflowRequestBuilder<static>|AiWorkflowRequest withTag(string $tag)
 *
 * @mixin \Eloquent
 */
class AiWorkflowRequest extends Model
{
    /**
     * Transient attribute carrying the human-approved answer for this request
     * during an eval run. Set in memory (never persisted) by the golden-set
     * assembly so judges can score against the human label rather than
     * re-deriving it from the recorded response.
     */
    public const string GROUND_TRUTH_ATTRIBUTE = 'eval_ground_truth';

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
        'thought_tokens',
        'duration_ms',
        'schema',
        'error',
        'http_status',
        'response_body',
        'error_class',
        'metadata',
        'tags',
        'template_variables',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'structured_response' => 'array',
            'schema' => 'array',
            'metadata' => 'array',
            'tags' => 'array',
            'template_variables' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'thought_tokens' => 'integer',
            'duration_ms' => 'integer',
            'http_status' => 'integer',
        ];
    }

    /**
     * @param  Builder  $query
     * @return AiWorkflowRequestBuilder<AiWorkflowRequest>
     */
    #[Override]
    public function newEloquentBuilder($query): AiWorkflowRequestBuilder
    {
        return new AiWorkflowRequestBuilder($query);
    }

    /**
     * @return BelongsTo<AiWorkflowExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowExecution::class, 'execution_id');
    }

    /**
     * @return HasMany<AiWorkflowAnnotation, $this>
     */
    public function annotations(): HasMany
    {
        return $this->hasMany(AiWorkflowAnnotation::class, 'request_id');
    }
}
