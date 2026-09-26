<?php

declare(strict_types=1);

namespace AiWorkflow;

use Prism\Prism\Exceptions\PrismStructuredDecodingException;
use Prism\Prism\Structured\Response as StructuredResponse;

/**
 * Rejects a Prism structured response that holds INF or NaN by throwing
 * PrismStructuredDecodingException.
 */
final class StructuredResponseGuard
{
    public static function rejectNonFiniteNumbers(StructuredResponse $response): StructuredResponse
    {
        if ($response->structured !== null && self::holdsNonFiniteNumber($response->structured)) {
            throw PrismStructuredDecodingException::make($response->text);
        }

        return $response;
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function holdsNonFiniteNumber(array $values): bool
    {
        foreach ($values as $value) {
            if (is_float($value) && ! is_finite($value)) {
                return true;
            }

            if (is_array($value) && self::holdsNonFiniteNumber($value)) {
                return true;
            }
        }

        return false;
    }
}
