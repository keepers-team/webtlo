<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Clients\ClientType;

/**
 * Параметры для доступа к торрент-клиенту.
 */
final class TorrentClientOptions
{
    public function __construct(
        public readonly ClientType $type,
        public readonly string     $host,
        public readonly int        $port,
        public readonly bool       $secure = false,
        public readonly ?BasicAuth $credentials = null,
        public readonly Timeout    $timeout = new Timeout(),
        public readonly array      $extra = [],
    ) {
    }

    /**
     * @return array{timeout: int, connect_timeout: int}
     */
    public function getTimeoutOptions(): array
    {
        return [
            'timeout'         => $this->timeout->request,
            'connect_timeout' => $this->timeout->connection,
        ];
    }

    /**
     * @return array{}|array{auth: array{string, string}}
     */
    public function getBasicAuth(): array
    {
        if (null === $this->credentials) {
            return [];
        }

        return [
            'auth' => [
                $this->credentials->username,
                $this->credentials->password,
            ],
        ];
    }

    /**
     * Параметры клиента из данных в конфиге.
     */
    public static function fromConfigProperties(array $options): self
    {
        $auth = null;
        if (!empty($options['lg']) && !empty($options['pw'])) {
            $auth = new BasicAuth((string)$options['lg'], (string)$options['pw']);
        }

        return new self(
            ClientType::from((string)$options['cl']),
            (string)$options['ht'],
            (int)$options['pt'],
            (bool)$options['ssl'],
            $auth
        );
    }

    /**
     * Параметры клиента из данных в конфиге.
     */
    public static function fromFrontProperties(array $options): self
    {
        $auth = null;
        if (!empty($options['login']) && !empty($options['password'])) {
            $auth = new BasicAuth((string)$options['login'], (string)$options['password']);
        }

        return new self(
            ClientType::from((string)$options['type']),
            (string)$options['hostname'],
            (int)$options['port'],
            (bool)$options['ssl'],
            $auth
        );
    }
}
