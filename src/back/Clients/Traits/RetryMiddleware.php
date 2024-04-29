<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

use GuzzleHttp\HandlerStack;

trait RetryMiddleware
{
    use \KeepersTeam\Webtlo\External\Shared\RetryMiddleware {
        getDefaultHandler as getSharedHandler;
    }

    protected function getDefaultHandler(?callable $handle = null): HandlerStack
    {
        return self::getSharedHandler($this->logger, $handle);
    }
}
