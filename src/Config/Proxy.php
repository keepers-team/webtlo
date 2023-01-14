<?php

namespace KeepersTeam\Webtlo\Config;

final class Proxy
{
    public function __construct(
        public readonly string $hostname = Defaults::proxyUrl,
        public readonly int $port = Defaults::proxyPort,
        public readonly ProxyType $type = ProxyType::SOCKS5H,
        public readonly ?string $login = null,
        public readonly ?string $password = null,
        public readonly bool $enabled = true,
    ) {
    }
}
