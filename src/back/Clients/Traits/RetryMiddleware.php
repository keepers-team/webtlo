<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

use Closure;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Psr\Http\Message\RequestInterface;

trait RetryMiddleware
{
    protected function getDefaultHandler(?callable $handle = null): HandlerStack
    {
        $stack = HandlerStack::create();
        if (null !== $handle) {
            $stack->push($handle);
        }
        $stack->push($this->getRetryMiddleware());

        return $stack;
    }

    protected function getRetryMiddleware(): Closure
    {
        $logger = $this->logger;

        $callback = static function(
            int              $attempt,
            float            $delay,
            RequestInterface $request,
        ) use ($logger): void {
            $logger->warning(
                'Retrying request',
                [
                    'url'     => $request->getUri()->__toString(),
                    'delay'   => number_format($delay, 2),
                    'attempt' => $attempt,
                ]
            );
        };

        return GuzzleRetryMiddleware::factory([
            'max_retry_attempts' => 3,
            'retry_on_timeout'   => true,
            'on_retry_callback'  => $callback,
        ]);
    }
}
