<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Traits;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Storage\Table\Topics;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;

trait DbClearTables
{
    /**
     * Очистка таблиц от неактуальных данных.
     */
    protected function clearTables(int $keepDataPeriod = 7): void
    {
        // Вручную создаём экземпляр UpdateTime.
        $updateTime = new UpdateTime(db: $this);

        // Проверяем необходимость выполнения очистки БД (раз в день).
        $isCleanNeeded = $updateTime->checkUpdateAvailable(marker: UpdateMark::DB_CLEAN, seconds: 86400);
        if (!$isCleanNeeded) {
            return;
        }

        $this->logger->debug('Выполняем очистку устаревших данных, записи старше {days} дней.', ['days' => $keepDataPeriod]);

        // Данные о сидах устарели
        $outdatedDate = (new DateTimeImmutable())->modify("- $keepDataPeriod day");

        // Удалим устаревшие метки обновлений.
        $updateTime->removeOutdatedRows(outdatedDate: $outdatedDate);

        // Удалим раздачи из подразделов, для которых нет актуальных меток обновления.
        $topics = new Topics(db: $this);
        $topics->removeOutdatedRows();

        // Записываем дату последней очистки.
        $updateTime->setMarkerTime(marker: UpdateMark::DB_CLEAN);

        $this->logger->debug('Очистка выполнена. Записываем маркер.');
    }
}
