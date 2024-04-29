<?php

namespace KeepersTeam\Webtlo\Legacy;

use KeepersTeam\Webtlo\Config\ProxyType;

/** Установка параметров прокси. */
final class Proxy
{
    public static array $proxy = [
        'forum' => [],
        'api'   => [],
    ];

    protected static ?string $auth;
    protected static ?string $type;
    protected static ?string $address;

    private static array $types = ['http' => 0, 'socks4' => 4, 'socks4a' => 6, 'socks5' => 5, 'socks5h' => 7];

    public static function options(
        bool   $activate_forum,
        bool   $activate_api,
        string $type,
        string $address = '',
        string $auth = ''
    ): void {
        self::$type    = array_key_exists($type, self::$types) ? self::$types[$type] : null;
        self::$address = in_array(null, explode(':', $address)) ? null : $address;
        self::$auth    = in_array(null, explode(':', $auth)) ? null : $auth;

        if ($activate_forum || $activate_api) {
            self::$proxy = self::set_proxy($activate_forum, $activate_api);
        }
    }

    private static function set_proxy(bool $activate_forum, bool $activate_api): array
    {
        $param = [
            CURLOPT_PROXYTYPE    => self::$type,
            CURLOPT_PROXY        => self::$address,
            CURLOPT_PROXYUSERPWD => self::$auth,
        ];

        $param_forum = $activate_forum ? $param : [];
        $param_api   = $activate_api ? $param : [];

        return [
            'forum' => $param_forum,
            'api'   => $param_api,
        ];
    }

    public static function getInfo(): string
    {
        return sprintf(
            'Используется %s-прокси: "%s".',
            mb_strtoupper(ProxyType::from((int)self::$type)->name),
            self::$address
        );
    }
}
