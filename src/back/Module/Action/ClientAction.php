<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Action;

enum ClientAction: string
{
    case Start = 'start';
    case Stop  = 'stop';

    case Remove  = 'remove';
    case Recheck = 'recheck';

    case SetLabel    = 'set_label';
    case SetLocation = 'set_location';
}
