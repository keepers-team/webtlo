<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Database;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Storage\Table\Topics;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use Psr\Log\LoggerInterface;

/**
 * Очистка таблиц от неактуальных записей.
 */
final class Cleaner
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int             $keepDataPeriod,
    ) {}

    public function clearTables(ConnectionInterface $con): void
    {
        // Вручную создаём экземпляр UpdateTime.
        $updateTime = new UpdateTime(con: $con);

        // Проверяем необходимость выполнения очистки БД (раз в день).
        $isCleanNeeded = $updateTime->checkUpdateAvailable(marker: UpdateMark::DB_CLEAN, seconds: 86400);
        if (!$isCleanNeeded) {
            return;
        }

        $this->logger->debug(
            'Выполняем очистку устаревших данных, записи старше {days} дней.',
            ['days' => $this->keepDataPeriod]
        );

        // Данные о сидах устарели
        $outdatedDate = (new DateTimeImmutable())->modify("- $this->keepDataPeriod day");

        // Удалим устаревшие метки обновлений.
        $updateTime->removeOutdatedRows(outdatedDate: $outdatedDate);

        // Удалим раздачи из подразделов, для которых нет актуальных меток обновления.
        $topics = new Topics(con: $con);
        $topics->removeOutdatedRows();

        // Записываем дату последней очистки.
        $updateTime->setMarkerTime(marker: UpdateMark::DB_CLEAN);

        $this->logger->debug('Очистка выполнена. Записываем маркер.');
    }
}
