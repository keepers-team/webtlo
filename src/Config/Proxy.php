<?php

namespace KeepersTeam\Webtlo\Config;

final class Proxy
{
    public function __construct(
        public readonly string $hostname = Defaults::proxyUrl,
        public readonly int $port = Defaults::proxyPort,
        public readonly ProxyType $type = ProxyType::SOCKS5H,
        public readonly ?Credentials $credentials = null,
        public readonly bool $enabled = true,
    ) {
    }
}
