<?php

declare(strict_types=1);

namespace AiWorkflow\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @template TModel of \AiWorkflow\Models\AiWorkflowAnnotation
 *
 * @extends Builder<TModel>
 */
class AiWorkflowAnnotationBuilder extends Builder
{
    /**
     * Keep only the most recent annotation for each request. The latest row
     * per request holds its current answer, so a request can be re-labelled
     * without deleting history.
     */
    public function latestPerRequest(): static
    {
        $table = $this->getModel()->getTable();

        $this->whereIn($this->getModel()->getQualifiedKeyName(), function (QueryBuilder $query) use ($table): void {
            $query->from($table)
                ->selectRaw('MAX(id)')
                ->groupBy('request_id');
        });

        return $this;
    }
}
