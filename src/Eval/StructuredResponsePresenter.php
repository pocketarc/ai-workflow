<?php

declare(strict_types=1);

namespace AiWorkflow\Eval;

/**
 * Recognises the "score every option" shape that classification prompts return
 * — a map of option => {likelihood, reasoning} — so a reviewer can read it as a
 * ranked table instead of raw JSON. Any other shape is left alone.
 */
class StructuredResponsePresenter
{
    /**
     * @param  array<string, mixed>  $structured
     * @return list<array{key: string, likelihood: float, reasoning: string}>|null
     *                                                                             Null when the response is not a map of scored options.
     */
    public static function ranked(array $structured): ?array
    {
        $rows = [];

        foreach ($structured as $key => $value) {
            // Skip anything that is not a scored option. These responses often
            // carry sibling fields alongside the options — decide_next_action
            // returns a `last_comment_summary` string next to its nine actions —
            // and bailing on those would drop the reader back to raw JSON.
            if (! is_array($value) || ! array_key_exists('likelihood', $value) || ! is_numeric($value['likelihood'])) {
                continue;
            }

            $reasoning = $value['reasoning'] ?? '';

            $rows[] = [
                'key' => $key,
                'likelihood' => (float) $value['likelihood'],
                'reasoning' => is_string($reasoning) ? $reasoning : '',
            ];
        }

        // One scored key is more likely a coincidence than a set of options.
        if (count($rows) < 2) {
            return null;
        }

        usort($rows, static fn (array $a, array $b): int => $b['likelihood'] <=> $a['likelihood']);

        return $rows;
    }

    /**
     * The scalar fields sitting alongside the scored options — context a
     * reviewer wants, like a summary of what just happened on the ticket.
     *
     * @param  array<string, mixed>  $structured
     * @return array<string, string>
     */
    public static function extras(array $structured): array
    {
        $extras = [];

        foreach ($structured as $key => $value) {
            if (is_string($value) && $value !== '') {
                $extras[$key] = $value;
            }
        }

        return $extras;
    }

    /**
     * The option the model picked — used to pre-fill the reviewer's label so
     * approving a decision is one click.
     *
     * @param  array<string, mixed>  $structured
     */
    public static function topKey(array $structured): ?string
    {
        return self::ranked($structured)[0]['key'] ?? null;
    }
}
