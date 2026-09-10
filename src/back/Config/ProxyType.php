<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Value for the <b>CURLOPT_PROXYTYPE</b> option.
 *
 * @see https://php.net/manual/en/curl.constants.php
 */
enum ProxyType: int
{
    case HTTP    = 0; // CURLPROXY_HTTP
    case HTTPS   = 2; // CURLPROXY_HTTPS
    case SOCKS4  = 4; // CURLPROXY_SOCKS4
    case SOCKS4A = 6; // CURLPROXY_SOCKS4A
    case SOCKS5  = 5; // CURLPROXY_SOCKS5
    case SOCKS5H = 7; // CURLPROXY_SOCKS5_HOSTNAME

    public static function tryFromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        return null;
    }
}
