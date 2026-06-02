<?php

declare(strict_types=1);

namespace AiWorkflow;

use Illuminate\Http\Client\RequestException;
use Prism\Prism\Exceptions\PrismException;
use Throwable;

/**
 * Reads the HTTP status and response body that Prism (or the underlying HTTP
 * client) attaches to a thrown exception.
 *
 * Prism exposes them as public properties on PrismException, which the
 * laravel-integrations classifier's accessor-based duck-typing can't see, so
 * both the request logger and the OpenRouter failure classifier read HTTP
 * context through here to stay consistent.
 */
final class PrismExceptionInspector
{
    /**
     * Walk the exception chain for the first carrier of HTTP context.
     *
     * @return array{status: ?int, body: ?string}
     */
    public static function extract(?Throwable $error): array
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof PrismException && ($e->httpStatus !== null || $e->responseBody !== null)) {
                return ['status' => $e->httpStatus, 'body' => $e->responseBody];
            }

            if ($e instanceof RequestException && $e->response !== null) {
                return ['status' => $e->response->status(), 'body' => $e->response->body()];
            }
        }

        return ['status' => null, 'body' => null];
    }

    public static function httpStatus(?Throwable $error): ?int
    {
        return self::extract($error)['status'];
    }
}
