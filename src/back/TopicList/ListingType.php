<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

/**
 * Тип выборки раздач (разворот).
 *
 * @see index.php #main-subsections
 */
enum ListingType: int
{
    /**
     * Раздачи из всех хранимых подразделов.
     */
    case AllKept = -3;

    /**
     * Раздачи с высоким приоритетом хранения.
     */
    case HighPriority = -5;

    /**
     * Раздачи из «чёрного списка».
     */
    case BlackListed = -2;

    /**
     * Хранимые дублирующиеся раздачи.
     */
    case Duplicated = -4;

    /**
     * Хранимые раздачи по спискам
     */
    case SelfKeep = -6;

    /**
     * Хранимые раздачи из других подразделов.
     */
    case OtherSubForums = 0;

    /**
     * Хранимые раздачи незарегистрированные на трекере.
     */
    case Unregistered = -1;

    public function getDefaultLabel(): string
    {
        return match ($this) {
            self::OtherSubForums,
            self::Unregistered,
            self::Duplicated => '__' . strtoupper($this->name) . '__',
            default          => '',
        };
    }

    /**
     * В зависимости от типа разворота, можно попробовать выставлять метку массово.
     */
    public function allowMassLabelSet(): bool
    {
        return match ($this) {
            self::AllKept,
            self::HighPriority,
            self::SelfKeep => true,
            default        => false,
        };
    }
}
