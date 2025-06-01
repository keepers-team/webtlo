<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Тип создаваемого подкаталога, при задании пути хранения файлов раздачи.
 */
enum SubFolderType: int
{
    case Topic = 1;
    case Hash  = 2;
}
