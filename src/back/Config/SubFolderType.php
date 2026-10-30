<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Тип создаваемых подкаталогов для хранения файлов раздачи.
 */
enum SubFolderType: int
{
    case Topic = 1;
    case Hash  = 2;

    // Шардинг по ид раздачи.
    case ShardTopic256 = 3;

    // Шардинг по двум символам хеша.
    case ShardHash2 = 4;
}
