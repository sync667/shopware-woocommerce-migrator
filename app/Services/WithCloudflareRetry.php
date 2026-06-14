<?php

namespace App\Services;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

trait WithCloudflareRetry
{
    protected static function makeRetryHandlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            static function (int $retries, $request, $response, $exception): bool {
                if ($retries >= 3) {
                    return false;
                }

                if ($exception instanceof ConnectException) {
                    return true;
                }

                if ($exception !== null) {
                    $msg = $exception->getMessage();
                    if (str_contains($msg, 'cURL error 28') || str_contains($msg, 'Operation timed out')) {
                        return true;
                    }
                }

                return $response !== null
                    && in_array($response->getStatusCode(), [521, 522, 524], true);
            },
            static function (int $retries): int {
                return (int) (1000 * (2 ** $retries));
            }
        ));

        return $stack;
    }
}
