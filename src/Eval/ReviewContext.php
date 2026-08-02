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

        if (! self::isSafeScheme($link['url'])) {
            throw new LogicException('Review context link URLs must be http, https or relative, got '.$link['url'].'.');
        }
    }

    /**
     * Blade escapes the href, but `javascript:` needs no escaping to run, and
     * a resolver may build a URL from a ticket field someone else wrote.
     */
    private static function isSafeScheme(string $url): bool
    {
        // A browser strips control characters and surrounding space before it
        // resolves a URL, so "java\nscript:alert(1)" runs, while parse_url()
        // finds no scheme in it and would otherwise pass it off as relative.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === false) {
            return false;
        }

        // No scheme is a relative link to the host app's own site.
        return $scheme === null || in_array(strtolower($scheme), ['http', 'https'], true);
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
