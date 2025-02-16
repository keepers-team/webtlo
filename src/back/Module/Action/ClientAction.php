<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Action;

enum ClientAction: string
{
    case Start    = 'start';
    case Stop     = 'stop';
    case Remove   = 'remove';
    case SetLabel = 'set_label';
}
