<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

/**
 * Возможные цвета пульки статуса раздачи.
 */
enum StateColor: string
{
    case Info    = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Danger  = 'danger';
    case Self    = 'self';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'полные данные о средних сидах',
            self::Warning => 'неполные данные о средних сидах',
            self::Danger  => 'отсутствуют данные о средних сидах',
            default       => '', // Ничего не выводим, если нет данных.
        };
    }
}
