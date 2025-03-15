<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

/**
 * Статус и иконка хранителя, в зависимости от состояния хранения раздачи у него.
 */
enum StateKeeperIcon: string
{
    case Seeding          = 'upload';
    case Inactive         = 'hard-drive';
    case NotListedSeeding = 'arrow-circle-o-up';
    case Downloading      = 'arrow-circle-o-down';

    public function label(): string
    {
        return match ($this) {
            self::Seeding          => 'Есть в списке и раздаёт',
            self::Inactive         => 'Есть в списке, не раздаёт',
            self::NotListedSeeding => 'Нет в списке и раздаёт',
            self::Downloading      => 'Скачивает',
        };
    }
}
