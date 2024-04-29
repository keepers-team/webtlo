<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Shared;

use Closure;
use GuzzleHttp\HandlerStack;
use GuzzleRetry\GuzzleRetryMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait RetryMiddleware
{
    protected static function getDefaultHandler(LoggerInterface $logger, ?callable $handle = null): HandlerStack
    {
        $stack = HandlerStack::create();
        if (null !== $handle) {
            $stack->push($handle);
        }
        $stack->push(self::getRetryMiddleware($logger));

        return $stack;
    }

    protected static function getRetryMiddleware(LoggerInterface $logger): Closure
    {
        $callback = static function(
            int                $attempt,
            float              $delay,
            RequestInterface   $request,
            array              $options,
            ?ResponseInterface $response,
        ) use ($logger): void {
            $reason = null !== $response ? $response->getStatusCode() : 'timeout';

            $attempts = $options['max_retry_attempts'];
            $logger->warning(
                sprintf('Повторная попытка выполнить запрос [%s/%s]', $attempt, $attempts),
                [
                    'url'    => $request->getUri()->getPath(),
                    'reason' => $reason,
                ]
            );

            $logger->debug(
                'Retrying request',
                [
                    'url'     => $request->getUri()->__toString(),
                    'delay'   => number_format($delay, 2),
                    'attempt' => $attempt,
                    'reason'  => $reason,
                ]
            );
        };

        return GuzzleRetryMiddleware::factory([
            'retry_on_timeout'   => true,
            'max_retry_attempts' => 3,
            'on_retry_callback'  => $callback,
        ]);
    }
}
