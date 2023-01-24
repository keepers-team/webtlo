<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

enum TorrentStatus: int
{
    case NotChecked = 0;
    case Closed = 1;
    case Checked = 2;
    case Malformed = 3;
    case NotFormed = 4;
    case Duplicate = 5;
    case Reserved = 6;
    case Absorbed = 7;
    case Doubtful = 8;
    case Checking = 9;
    case Temporary = 10;
    case PreModeration = 11;
}
