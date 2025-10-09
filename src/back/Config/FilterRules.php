<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Параметры для фильтрации раздач.
 */
final class FilterRules
{
    /**
     * @param int  $ruleTopics      количество сидов
     * @param int  $ruleDateRelease сдвиг даты релиза относительно текущей даты
     * @param bool $excludeSelf     исключения "себя" из списка раздач
     */
    public function __construct(
        public readonly int  $ruleTopics,
        public readonly int  $ruleDateRelease,
        public readonly bool $excludeSelf,
    ) {}
}
