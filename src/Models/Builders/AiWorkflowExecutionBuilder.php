<?php

declare(strict_types=1);

namespace AiWorkflow\Models\Builders;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \AiWorkflow\Models\AiWorkflowExecution
 *
 * @extends Builder<TModel>
 */
class AiWorkflowExecutionBuilder extends Builder
{
    public function recent(int $hours = 24): static
    {
        return $this->where('created_at', '>=', now()->subHours($hours));
    }

    public function byName(string $name): static
    {
        return $this->where('name', $name);
    }
}
