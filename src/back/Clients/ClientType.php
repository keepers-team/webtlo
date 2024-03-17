<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

enum ClientType: string
{
    case Deluge       = 'deluge';
    case Flood        = 'flood';
    case Qbittorrent  = 'qbittorrent';
    case Rtorrent     = 'rtorrent';
    case Transmission = 'transmission';
    case Utorrent     = 'utorrent';
}
