<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class Defaults
{
    final public const forumUrl     = 'rutracker.org';
    final public const apiForumUrl  = 'api.rutracker.cc';
    final public const apiReportUrl = 'rep.rutracker.cc';
    final public const downloadPath = 'C:\Temp\\';
    final public const proxyType    = ProxyType::SOCKS5H;
    final public const proxyUrl     = 'gateway.keepers.tech';
    final public const proxyPort    = 60789;
    final public const userAgent    = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:108.0) Gecko/20100101 Firefox/108.0';
    final public const timeout      = 40;
    final public const uiTheme      = 'smoothness';
}
