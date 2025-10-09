<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Front\DefaultConnectTrait;

final class ApiForumConnect
{
    use DefaultConnectTrait;

    /** @var string[] */
    final public const validUrl = [
        Defaults::apiForumUrl,
    ];

    /**
     * Количество возможны одновременных запросов к API.
     */
    final public const concurrency = 4;

    /**
     * Временной интервал, в который не должно отправлять более rateFrameLimit запросов, в мс.
     */
    final public const rateFrameSize = 1000;

    /**
     * Количество запросов, не более которого должно отправляться за rateFrameSize.
     */
    final public const rateRequestLimit = 2;

    private static string $apiVersion = 'v1';

    public function __construct(
        public readonly string  $baseUrl,
        public readonly bool    $isCustom,
        public readonly bool    $ssl,
        public readonly bool    $useProxy,
        public readonly Timeout $timeout,
        public readonly int     $concurrency,
        public readonly int     $rateFrameSize,
        public readonly int     $rateRequestLimit,
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
