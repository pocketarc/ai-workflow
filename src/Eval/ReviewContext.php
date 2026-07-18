<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

/**
 * Whatever the host app can add to a reviewed request: links to its own
 * records, and short notes giving the reviewer the surrounding situation.
 */
class ReviewContext
{
    /**
     * @param  list<array{label: string, url: string}>  $links
     * @param  array<string, string>  $notes  Heading => text, shown above the response.
     */
    public function __construct(
        public readonly array $links = [],
        public readonly array $notes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->links === [] && $this->notes === [];
    }
}
