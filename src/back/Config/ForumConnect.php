<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Front\DefaultConnectTrait;

final class ForumConnect
{
    use DefaultConnectTrait;

    /** @var string[] */
    final public const validUrl = [
        Defaults::forumUrl,
        'rutracker.net',
    ];

    public function __construct(
        public readonly string  $baseUrl,
        public readonly bool    $isCustom,
        public readonly bool    $ssl,
        public readonly bool    $useProxy,
        public readonly Timeout $timeout,
    ) {}

    public function buildUrl(): string
    {
        return sprintf(
            '%s://%s',
            $this->ssl ? 'https' : 'http',
            $this->baseUrl
        );
    }
}
