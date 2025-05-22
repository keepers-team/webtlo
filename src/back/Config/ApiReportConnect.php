<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class ApiReportConnect
{
    private static string $apiVersion = 'krs/api/v1';

    public function __construct(
        public readonly string  $baseUrl,
        public readonly bool    $ssl,
        public readonly bool    $useProxy,
        public readonly Timeout $timeout,
        public readonly string  $userAgent = Defaults::userAgent,
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
