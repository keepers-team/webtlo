<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Filter;

enum SortRule: string
{
    case NAME      = 'name';
    case SIZE      = 'size';
    case TOPIC_ID  = 'topic_id';
    case SEED      = 'seed';
    case REG_TIME  = 'reg_time';
    case CLIENT_ID = 'client_id';

    public function label(): string
    {
        return match ($this) {
            self::NAME      => 'Название раздачи',
            self::SIZE      => 'Размер раздачи',
            self::TOPIC_ID  => 'Ид раздачи',
            self::SEED      => 'Количество сидов (средних или моментальных)',
            self::REG_TIME  => 'Дата регистрации раздачи',
            self::CLIENT_ID => 'Торрент-клиент',
        };
    }
}