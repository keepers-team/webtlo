<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

enum ProxyType: int
{
    case HTTP    = 0;
    case HTTPS   = 2;
    case SOCKS4  = 4;
    case SOCKS4A = 6;
    case SOCKS5  = 5;
    case SOCKS5H = 7;

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
