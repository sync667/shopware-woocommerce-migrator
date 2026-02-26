<?php

namespace App\Services;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

/**
 * Guzzle handler stack with automatic retry on Cloudflare connectivity errors.
 *
 * CF error codes that indicate a transient origin-unreachable condition:
 *   521 – Web Server Is Down
 *   522 – Connection Timed Out
 *   524 – A Timeout Occurred
 *
 * Retries up to 3 times with exponential back-off: 1 s → 2 s → 4 s.
 */
trait WithCloudflareRetry
{
    protected static function makeRetryHandlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            static function (int $retries, $request, $response): bool {
                if ($retries >= 3) {
                    return false;
                }

                return $response !== null
                    && in_array($response->getStatusCode(), [521, 522, 524], true);
            },
            static function (int $retries): int {
                // 1 s, 2 s, 4 s
                return (int) (1000 * (2 ** ($retries - 1)));
            }
        ));

        return $stack;
    }
}
