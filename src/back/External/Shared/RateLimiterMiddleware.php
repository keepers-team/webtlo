<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Shared;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Loop;
use React\Promise\Promise;
use Throwable;

use function React\Async\await;

final class RateLimiterMiddleware
{
    public const MAX_TIMESTAMPS_STORED   = 50;
    public const MILLISECONDS_PER_SECOND = 1000;

    /** @var positive-int[] */
    private array $timestamps = [];

    public function __construct(
        private readonly int $frameSize,
        private readonly int $requestLimit,
    ) {}

    public function __invoke(callable $handler): callable
    {
        return function(RequestInterface $request, array $options) use ($handler) {
            return $this->handle(static function() use ($request, $handler, $options) {
                return $handler($request, $options);
            });
        };
    }

    private function handle(callable $callback, int $retry = 0): PromiseInterface
    {
        $delayUntilNextRequest = $this->delayUntilNextRequest();

        if ($delayUntilNextRequest > 0) {
            $this->sleep(milliseconds: $delayUntilNextRequest);

            // Проверяем ещё раз, если нужно.
            if (++$retry < 3) {
                return $this->handle(callback: $callback, retry: $retry);
            }
        }

        // Записываем время выполнения(проверки) в буфер.
        $this->pushCurrentTime();

        return $callback();
    }

    /**
     * Вычисляет необходимое время задержки перед следующим запросом,
     * чтобы соблюсти лимит запросов в заданный временной интервал.
     *
     * @return non-negative-int Время задержки в миллисекундах (0 если задержка не требуется)
     */
    private function delayUntilNextRequest(): int
    {
        // Если выполнено меньше запросов чем лимит - задержка не нужна
        if (count($this->timestamps) < $this->requestLimit) {
            return 0;
        }

        $currentTime = $this->getCurrentTime();
        $frameStart  = $currentTime - $this->frameSize;

        // Фильтруем запросы, попадающие в текущий временной интервал.
        $recentRequests = array_filter(
            $this->timestamps,
            fn(int $t): bool => $t >= $frameStart
        );

        // Если в текущем интервале меньше запросов чем лимит - задержка не нужна.
        if (count($recentRequests) < $this->requestLimit) {
            return 0;
        }

        $this->cleanOldTimestamps();

        // Вычисляем время, когда можно сделать следующий запрос.
        $nextAvailableTime = reset($recentRequests) + $this->frameSize;
        $delay             = $nextAvailableTime - $currentTime;

        return max($delay, 0);
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds < 50) {
            return;
        }

        $seconds = (float) ($milliseconds / self::MILLISECONDS_PER_SECOND);

        try {
            $promise = new Promise(function($resolve) use ($seconds) {
                // resolve the promise when the timer fires in $time seconds
                Loop::addTimer($seconds, function() use ($resolve) {
                    $resolve(null);
                });
            });

            await($promise);
        } catch (Throwable) {
        }
    }

    /**
     * @return positive-int
     */
    private function getCurrentTime(): int
    {
        $timestamp = (int) round(microtime(true) * self::MILLISECONDS_PER_SECOND);

        return max($timestamp, 1);
    }

    private function pushCurrentTime(): void
    {
        $this->timestamps[] = $this->getCurrentTime();
    }

    /**
     * Очищает историю запросов, оставляя только последние N записей.
     */
    private function cleanOldTimestamps(): void
    {
        $currentCount = count($this->timestamps);

        if ($currentCount > self::MAX_TIMESTAMPS_STORED) {
            $this->timestamps = array_slice(
                $this->timestamps,
                -self::MAX_TIMESTAMPS_STORED,
                self::MAX_TIMESTAMPS_STORED
            );
        }
    }
}
