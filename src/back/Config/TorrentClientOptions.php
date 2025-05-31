<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Clients\ClientType;

/**
 * Параметры для доступа к торрент-клиенту.
 */
final class TorrentClientOptions
{
    public readonly string $tag;

    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly int        $id,
        public readonly ClientType $type,
        public readonly string     $name,
        public readonly string     $host,
        public readonly int        $port,
        public readonly bool       $secure = false,
        public readonly ?BasicAuth $credentials = null,
        public readonly Timeout    $timeout = new Timeout(),
        public readonly bool       $exclude = false,
        public readonly int        $controlPeers = -2,
        public readonly array      $extra = [],
    ) {
        $tag = $this->name ?: $this->type->name;
        if ($this->id > 0) {
            $tag .= "($this->id)";
        }

        // client-name(1)
        $this->tag = $tag;
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
        if ($this->credentials === null) {
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
     *
     * @param array<string, mixed> $options
     */
    public static function fromConfigProperties(array $options): self
    {
        $auth = null;
        if (!empty($options['lg']) && !empty($options['pw'])) {
            $auth = new BasicAuth((string) $options['lg'], (string) $options['pw']);
        }

        $timeout = new Timeout(
            (int) ($options['request_timeout'] ?? Defaults::timeout),
            (int) ($options['connect_timeout'] ?? Defaults::timeout),
        );

        // Если не передан порт подключения к клиенту, добавляем порт по умолчанию.
        $ssl = (bool) $options['ssl'];
        if (empty($options['pt'])) {
            $options['pt'] = $ssl ? 443 : 80;
        }

        return new self(
            type       : ClientType::from((string) $options['cl']),
            host       : (string) $options['ht'],
            port       : (int) $options['pt'],
            secure     : $ssl,
            credentials: $auth,
            timeout    : $timeout,
            extra      : [
                'id'      => $options['id'] ?? 0,
                'comment' => $options['cm'] ?? '',
            ],
        );
    }

    /**
     * Параметры клиента из данных в конфиге.
     *
     * @param array<string, mixed> $options
     */
    public static function fromFrontProperties(array $options): self
    {
        $auth = null;
        if (!empty($options['login']) && !empty($options['password'])) {
            $auth = new BasicAuth((string) $options['login'], (string) $options['password']);
        }

        // Если не передан порт подключения к клиенту, добавляем порт по умолчанию.
        $ssl = (bool) $options['ssl'];
        if (empty($options['port'])) {
            $options['port'] = $ssl ? 443 : 80;
        }

        return new self(
            id         : 0,
            type       : ClientType::from((string) $options['type']),
            name       : (string) $options['comment'],
            host       : (string) $options['hostname'],
            port       : (int) $options['port'],
            secure     : $ssl,
            credentials: $auth,
            extra      : ['comment' => $options['comment']],
        );
    }
}
