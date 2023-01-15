<?php

namespace KeepersTeam\Webtlo\Clients;

enum SupportedClientType
{
    case Deluge;
    case Flood;
    case Ktorrent;
    case Qbittorrent;
    case Rtorrent;
    case Transmission;
    case Utorrent;
}
