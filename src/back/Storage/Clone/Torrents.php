<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Clone;

use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Storage\CloneTable;
use KeepersTeam\Webtlo\Storage\KeysObject;
use PDO;

/**
 * Временная таблица с раздачами из торрент-клиентов.
 */
final class Torrents
{
    // Параметры таблицы
    public const TABLE   = 'Torrents';
    public const PRIMARY = 'info_hash';
    public const KEYS    = [
        self::PRIMARY,
        'topic_id',
        'client_id',
        'done',
        'error',
        'name',
        'paused',
        'time_added',
        'total_size',
        'tracker_error',
    ];

    /** @var array<int, mixed>[] */
    private array $torrents = [];

    public function __construct(
        private readonly DB         $db,
        private readonly CloneTable $clone,
    ) {}

    public function addTorrent(int $clientId, Torrent $torrent): void
    {
        $this->torrents[] = [
            $torrent->topicHash,
            $torrent->topicId,
            $clientId,
            $torrent->done,
            (int) $torrent->error,
            $torrent->name,
            (int) $torrent->paused,
            $torrent->added->getTimestamp(),
            $torrent->size,
            $torrent->trackerError,
        ];
    }

    /**
     * Записать часть раздач во временную таблицу.
     */
    public function cloneFill(): void
    {
        if (!count($this->torrents)) {
            return;
        }

        $tab = $this->clone;

        $rows = array_map(fn($el) => array_combine($tab->getTableKeys(), $el), $this->torrents);
        $tab->cloneFillChunk($rows);

        $this->torrents = [];
    }

    public function writeTable(): int
    {
        return $this->clone->writeTable();
    }

    /**
     * Удалить ненужные строки о хранимых раздачах из торрент-клиентов.
     */
    public function removeUnusedTorrentsRows(KeysObject $failedClients): void
    {
        $tab = $this->clone->getTableObject();

        $this->db->executeStatement(
            "
                DELETE FROM $tab->origin
                WHERE client_id NOT IN ($failedClients->keys) AND (
                    info_hash || client_id NOT IN (
                        SELECT ins.info_hash || ins.client_id
                        FROM $tab->clone AS tmp
                        INNER JOIN $tab->origin AS ins ON tmp.info_hash = ins.info_hash AND tmp.client_id = ins.client_id
                    ) OR client_id NOT IN (
                        SELECT DISTINCT client_id FROM $tab->clone
                    )
                )
            ",
            $failedClients->values,
        );
    }

    /**
     * Найти в БД раздачи, которых нет в хранимых подразделах.
     *
     * @return string[]
     */
    public function selectUntrackedRows(KeysObject $subsections): array
    {
        $tab = $this->clone->getTableObject();

        $stm = $this->db->executeStatement(
            "
                SELECT tmp.info_hash
                FROM $tab->clone AS tmp
                LEFT JOIN Topics ON Topics.info_hash = tmp.info_hash
                WHERE
                    Topics.id IS NULL
                    OR Topics.forum_id NOT IN ($subsections->keys)
            ",
            $subsections->values
        );

        return $stm->fetchAll(PDO::FETCH_COLUMN);
    }

    public function clearOriginTable(): void
    {
        $this->db->executeStatement('DELETE FROM Torrents WHERE TRUE');
    }
}
