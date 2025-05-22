<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class ApiForumConnect
{
    private static string $apiVersion = 'v1';

    public function __construct(
        public readonly string  $baseUrl,
        public readonly bool    $ssl,
        public readonly bool    $useProxy,
        public readonly Timeout $timeout,
        public readonly string  $userAgent = Defaults::userAgent,
        public readonly int     $concurrency = 4,
        public readonly int     $rateFrameSize = 1000,
        public readonly int     $rateRequestLimit = 2,
    ) {}

    public function getApiUrl(): string
    {
        return sprintf(
            '%s://%s/%s/',
            $this->ssl ? 'https' : 'http',
            $this->baseUrl,
            self::$apiVersion,
        );
    }
}
