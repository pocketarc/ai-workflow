<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

use LogicException;

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
    ) {
        // Host apps build these, and a wrong shape would otherwise only
        // surface as a fatal when the review page renders. Rejecting it here
        // keeps the failure inside the resolver call, where the controller
        // already catches and reports it.
        foreach ($this->links as $link) {
            self::assertLink($link);
        }

        foreach ($this->notes as $heading => $note) {
            self::assertNote($heading, $note);
        }
    }

    private static function assertLink(mixed $link): void
    {
        if (! is_array($link) || ! is_string($link['label'] ?? null) || ! is_string($link['url'] ?? null)) {
            throw new LogicException('Review context links must be arrays with string label and url, got '.get_debug_type($link).'.');
        }
    }

    private static function assertNote(mixed $heading, mixed $note): void
    {
        if (! is_string($heading) || ! is_string($note)) {
            throw new LogicException('Review context notes must map string headings to string text, got '.get_debug_type($heading).' => '.get_debug_type($note).'.');
        }
    }

    public function isEmpty(): bool
    {
        return $this->links === [] && $this->notes === [];
    }
}
