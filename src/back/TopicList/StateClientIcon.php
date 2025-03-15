<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

/**
 * Статус и иконка раздачи в зависимости от её состояния у текущего хранителя.
 */
enum StateClientIcon: string
{
    case NotAdded    = 'circle';
    case Seeding     = 'arrow-circle-o-up';
    case Downloading = 'arrow-circle-o-down';
    case Paused      = 'pause-circle-o';
    case Error       = 'times-circle-o';

    public function label(): string
    {
        return match ($this) {
            self::NotAdded    => 'нет в клиенте',
            self::Seeding     => 'раздаётся',
            self::Downloading => 'скачивается',
            self::Paused      => 'приостановлена',
            self::Error       => 'с ошибкой в клиенте',
        };
    }
}
