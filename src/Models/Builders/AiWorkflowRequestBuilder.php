<?php

declare(strict_types=1);

namespace AiWorkflow\Models\Builders;

use AiWorkflow\PromptData;
use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of \AiWorkflow\Models\AiWorkflowRequest
 *
 * @extends Builder<TModel>
 */
class AiWorkflowRequestBuilder extends Builder
{
    public function withTag(string $tag): static
    {
        $this->whereJsonContains('tags', $tag);

        return $this;
    }

    /**
     * @param  list<string>  $tags
     */
    public function withAnyTag(array $tags): static
    {
        $this->where(function (Builder $q) use ($tags): void {
            foreach ($tags as $tag) {
                $q->orWhereJsonContains('tags', $tag);
            }
        });

        return $this;
    }

    public function byModel(string $model): static
    {
        if (str_contains($model, ':')) {
            [$provider, $modelName] = PromptData::parseModelIdentifier($model);

            return $this->where('provider', $provider)->where('model', $modelName);
        }

        return $this->where('model', $model);
    }

    public function byPrompt(string $promptId): static
    {
        return $this->where('prompt_id', $promptId);
    }

    public function errors(): static
    {
        $this->whereNotNull('error');

        return $this;
    }

    public function successful(): static
    {
        $this->whereNull('error');

        return $this;
    }
}
