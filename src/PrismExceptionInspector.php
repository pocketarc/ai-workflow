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
     * Walk the exception chain, collecting status and body independently — the
     * outermost carrier of each wins. A wrapper often has only one of the two
     * (a body-only PrismException around a RequestException, say), and stopping
     * at it would drop the status an inner exception still holds — turning a
     * 402/403 into a status-less failure the classifier calls Upstream.
     *
     * @return array{status: ?int, body: ?string}
     */
    public static function extract(?Throwable $error): array
    {
        $status = null;
        $body = null;

        for ($e = $error; $e !== null && ($status === null || $body === null); $e = $e->getPrevious()) {
            if ($e instanceof PrismException) {
                $status ??= $e->httpStatus;
                $body ??= $e->responseBody;
            }

            if ($e instanceof RequestException && $e->response !== null) {
                $status ??= $e->response->status();
                $body ??= $e->response->body();
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    public static function httpStatus(?Throwable $error): ?int
    {
        return self::extract($error)['status'];
    }
}
