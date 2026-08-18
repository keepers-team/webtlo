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

    public readonly string $url;

    public function __construct(
        public readonly string  $baseUrl,
        public readonly string  $customUrl,
        public readonly bool    $isCustom,
        public readonly bool    $useProxy,
        public readonly Timeout $timeout,
    ) {
        $this->url = sprintf(
            'https://%s',
            $this->baseUrl
        );
    }
}
