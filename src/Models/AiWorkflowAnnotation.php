<?php

declare(strict_types=1);

namespace AiWorkflow\Models;

use AiWorkflow\Models\Builders\AiWorkflowAnnotationBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Override;

/**
 * A human review of a recorded AI request: the answer that was correct for it
 * (e.g. the right label for a classification prompt), an optional free-text
 * reason, and who recorded it. Feeds the eval golden set.
 *
 * @method static AiWorkflowAnnotationBuilder<AiWorkflowAnnotation> newModelQuery()
 * @method static AiWorkflowAnnotationBuilder<AiWorkflowAnnotation> newQuery()
 * @method static AiWorkflowAnnotationBuilder<AiWorkflowAnnotation> query()
 *
 * @property int $id
 * @property int $request_id
 * @property string|null $label
 * @property string|null $reason
 * @property string|null $reviewer
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AiWorkflowRequest $request
 *
 * @method static AiWorkflowAnnotationBuilder<static>|AiWorkflowAnnotation latestPerRequest()
 *
 * @mixin \Eloquent
 */
class AiWorkflowAnnotation extends Model
{
    protected $table = 'ai_workflow_annotations';

    protected $fillable = [
        'request_id',
        'label',
        'reason',
        'reviewer',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @param  Builder  $query
     * @return AiWorkflowAnnotationBuilder<AiWorkflowAnnotation>
     */
    #[Override]
    public function newEloquentBuilder($query): AiWorkflowAnnotationBuilder
    {
        return new AiWorkflowAnnotationBuilder($query);
    }

    /**
     * @return BelongsTo<AiWorkflowRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AiWorkflowRequest::class, 'request_id');
    }
}
