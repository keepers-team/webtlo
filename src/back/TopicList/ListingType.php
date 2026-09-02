<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

/**
 * Тип выборки раздач (разворот).
 *
 * @see public/index.php #main-subsections
 * @see public/scripts/lib.topics.js TopicListingType
 */
enum ListingType: int
{
    /**
     * Старые ид типа выборки, для совместимости.
     *
     * @TODO убрать в 5.0
     */
    private const FallBack = [
        -3 => -10,
        -5 => -15,
        -6 => -20,
        -4 => -21,
        -2 => -22,
        0  => -30,
        -1 => -31,
    ];

    /**
     * Раздачи из всех хранимых подразделов (отображаемых).
     */
    case AllKeptShowed = -10;

    /**
     * Раздачи из всех хранимых подразделов (скрытых).
     */
    case AllKeptHidden = -11;

    /**
     * Раздачи с высоким приоритетом хранения.
     */
    case HighPriority = -15;

    /**
     * Хранимые раздачи по спискам.
     */
    case SelfKeep = -20;

    /**
     * Хранимые дублирующиеся раздачи.
     */
    case Duplicated = -21;

    /**
     * Раздачи из «чёрного списка».
     */
    case BlackListed = -22;

    /**
     * Хранимые раздачи из других подразделов.
     */
    case OtherSubForums = -30;

    /**
     * Хранимые раздачи незарегистрированные на трекере.
     */
    case Unregistered = -31;

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
            self::AllKeptShowed,
            self::AllKeptHidden,
            self::HighPriority,
            self::SelfKeep => true,
            default        => false,
        };
    }

    /**
     * Получить экземпляр enum, с поддержкой старых значений.
     */
    public static function tryFallBack(int $value): ?self
    {
        if (isset(self::FallBack[$value])) {
            return self::tryFrom(self::FallBack[$value]);
        }

        return self::tryFrom($value);
    }
}
