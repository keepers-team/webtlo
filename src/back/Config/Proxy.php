<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class Proxy
{
    public function __construct(
        public readonly string     $hostname = Defaults::proxyUrl,
        public readonly int        $port = Defaults::proxyPort,
        public readonly ProxyType  $type = ProxyType::SOCKS5H,
        public readonly ?BasicAuth $credentials = null,
    ) {
    }

    public function getOptions(): array
    {
        $curlOptions = [CURLOPT_PROXYTYPE => $this->type->value];

        $needsAuth = null !== $this->credentials;
        if ($needsAuth) {
            $curlOptions[CURLOPT_PROXYUSERPWD] = sprintf(
                "%s:%s",
                $this->credentials->username,
                $this->credentials->password
            );
        }

        return [
            'proxy' => sprintf("%s:%d", $this->hostname, $this->port),
            'curl'  => $curlOptions,
        ];
    }

    public function log(): array
    {
        return [
            'hostname'      => $this->hostname,
            'port'          => $this->port,
            'type'          => $this->type->name,
            'authenticated' => null !== $this->credentials,
        ];
    }

    public static function fromLegacy(array $cfg): self
    {
        $proxyType = ProxyType::tryFromName(strtoupper((string)$cfg['proxy_type']));

        $proxyAuth = null;
        if (!empty($cfg['proxy_login']) && !empty($cfg['proxy_paswd'])) {
            $proxyAuth = new BasicAuth(
                $cfg['proxy_login'],
                $cfg['proxy_paswd']
            );
        }

        return new self(
            (string)$cfg['proxy_hostname'],
            (int)$cfg['proxy_port'],
            $proxyType,
            $proxyAuth
        );
    }
}
