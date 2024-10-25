<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Traits;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use KeepersTeam\Webtlo\TIniFileEx;

trait DbClearTables
{
    /**
     * Очистка таблиц от неактуальных данных.
     * TODO Изменить работу с конфигом.
     */
    protected function clearTables(): void
    {
        $updateTime = new UpdateTime($this);

        // Проверяем необходимость выполнения очистки БД (раз в день).
        $isCleanNeeded = $updateTime->checkUpdateAvailable(UpdateMark::DB_CLEAN->value, 86400);
        if (!$isCleanNeeded) {
            return;
        }

        // Данные о сидах устарели
        $keepDataPeriod = (int) TIniFileEx::read('sections', 'avg_seeders_period_outdated', 7);
        $outdatedDate   = (new DateTimeImmutable())->modify("- $keepDataPeriod day");

        // Удалим устаревшие метки обновлений.
        $updateTime->removeOutdatedRows($outdatedDate);

        // Удалим раздачи из подразделов, для которых нет актуальных меток обновления.
        $this->executeStatement(
            '
                DELETE FROM Topics
                WHERE keeping_priority <> 2
                    AND forum_id NOT IN (SELECT id FROM UpdateTime WHERE id < 100000)
            '
        );

        // Если используется алгоритм получения раздач высокого приоритета - их тоже нужно чистить.
        $updatePriority = (bool) TIniFileEx::read('update', 'priority', 0);
        if ($updatePriority) {
            // Удалим устаревшие раздачи высокого приоритета.
            $highPriorityUpdate = $updateTime->getMarkerTime(UpdateMark::HIGH_PRIORITY->value);
            if ($highPriorityUpdate < $outdatedDate) {
                $this->executeStatement(
                    '
                        DELETE FROM Topics
                        WHERE keeping_priority = 2
                            AND forum_id NOT IN (SELECT id FROM UpdateTime WHERE id < 100000)
                    '
                );
            }
        }

        // Записываем дату последней очистки.
        $updateTime->setMarkerTime(UpdateMark::DB_CLEAN->value);
    }
}
