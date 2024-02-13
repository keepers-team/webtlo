<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Tables\Topics;
use Psr\Log\LoggerInterface;

final class Subsections
{
    private ?CloneTable $tableUpdate = null;
    private ?CloneTable $tableInsert = null;

    private const KEYS_UPDATE = [
        'id',
        'forum_id',
        'seeders',
        'status',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ];
    private const KEYS_INSERT = [
        'id',
        'forum_id',
        'name',
        'info_hash',
        'seeders',
        'size',
        'status',
        'reg_time',
        'seeders_updates_today',
        'seeders_updates_days',
        'keeping_priority',
        'poster',
        'seeder_last_seen',
    ];

    private array $topicsUpdate = [];
    private array $topicsInsert = [];
    private array $topicsDelete = [];

    public function __construct(
        private readonly Topics          $topics,
        private readonly DB              $db,
        private readonly LoggerInterface $logger
    ) {
    }

    public function addTopicForUpdate(array $topic): void
    {
        $this->topicsUpdate[] = array_combine(self::KEYS_UPDATE, $topic);
    }

    public function addTopicForInsert(array $topic): void
    {
        $this->topicsInsert[] = array_combine(self::KEYS_INSERT, $topic);
    }

    public function fillTempTables(): void
    {
        $this->initTempTables();

        if (count($this->topicsUpdate)) {
            $this->tableUpdate->cloneFill($this->topicsUpdate);
            $this->topicsUpdate = [];
        }

        if (count($this->topicsInsert)) {
            $this->tableInsert->cloneFill($this->topicsInsert);
            $this->topicsInsert = [];
        }
    }

    private function initTempTables(): void
    {
        if (null === $this->tableUpdate) {
            $this->tableUpdate = CloneTable::create(Topics::TABLE, self::KEYS_UPDATE, Topics::PRIMARY, 'Update');
        }
        if (null === $this->tableInsert) {
            $this->tableInsert = CloneTable::create(Topics::TABLE, self::KEYS_INSERT, Topics::PRIMARY, 'Insert');
        }
    }

    public function markTopicDelete(int $topicId): void
    {
        $this->topicsDelete[] = $topicId;
    }

    public function deleteTopics(): void
    {
        if (count($this->topicsDelete)) {
            $topics = array_unique($this->topicsDelete);

            $this->logger->debug(sprintf('Удалено перезалитых раздач %d шт.', count($topics)));
            $this->topics->deleteTopicsByIds($topics);
        }
    }

    public function moveToOrigin(array $updatedSubsections): void
    {
        $this->initTempTables();

        // Переносим данные в основную таблицу.
        $countTopicsUpdate = $this->moveRowsInTable($this->tableUpdate);
        $countTopicsInsert = $this->moveRowsInTable($this->tableInsert);

        // Удаляем ненужные раздачи.
        $this->clearUnusedTopics($updatedSubsections);

        $this->logger->info(
            sprintf(
                'Обработано хранимых подразделов: %d шт, раздач в них %d шт.',
                count($updatedSubsections),
                $countTopicsUpdate + $countTopicsInsert
            )
        );
    }

    private function moveRowsInTable(CloneTable $table): int
    {
        $count = $table->cloneCount();
        if ($count > 0) {
            $table->moveToOrigin();
        }

        return $count;
    }

    private function clearUnusedTopics(array $updatedSubsections): void
    {
        $in = implode(',', $updatedSubsections);

        $query = "
            DELETE
            FROM Topics
            WHERE forum_id IN ($in)
                AND id NOT IN (
                    SELECT {$this->tableUpdate->primary} FROM {$this->tableUpdate->clone}
                    UNION ALL
                    SELECT {$this->tableInsert->primary} FROM {$this->tableInsert->clone}
                )
        ";
        $this->db->executeStatement($query);

        $unused = (int)$this->db->queryColumn('SELECT CHANGES()');
        if ($unused > 0) {
            $this->logger->debug(sprintf('Удалено лишних раздач %d шт.', $unused));
        }
    }
}
