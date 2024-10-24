<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

use Generator;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Timers;
use PDO;
use Psr\Log\LoggerInterface;

final class DbSearch
{
    /**
     * Максимальное количество переменных для SQLite < 3.32
     *
     * https://www.sqlite.org/limits.html
     */
    private const SQLITE_MAX_VARIABLE_NUMBER = 999;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DB              $db,
    ) {}

    public function getStoredHashes(KeysObject $forums, Torrents $torrents, string $timer): Generator
    {
        Timers::start($timer);

        $unknownHashes = $torrents->getHashes();

        $chunkLimit   = max(1, self::SQLITE_MAX_VARIABLE_NUMBER - count($forums->values));
        $hashesChunks = array_chunk($unknownHashes, $chunkLimit);

        $topicsHashes = [];
        foreach ($hashesChunks as $chunk) {
            $chunk = KeysObject::create($chunk);

            $query = "
                SELECT forum_id, info_hash
                FROM Topics
                WHERE
                    info_hash IN ($chunk->keys)
                    AND forum_id IN ($forums->keys)
            ";

            $result = $this->db->query(
                $query,
                array_merge($chunk->values, $forums->values),
                PDO::FETCH_GROUP | PDO::FETCH_COLUMN,
            );

            foreach ($result as $forumId => $hashes) {
                // Найденные хеши вычитаем из общей кучи.
                $unknownHashes = array_diff($unknownHashes, $hashes);

                $topicsHashes[$forumId][] = $hashes;
            }
        }

        $topicsHashes = array_map(fn($el) => array_merge(...$el), $topicsHashes);

        $this->logger->info(
            'Поиск раздач в БД завершён за {sec}. Найдено раздач из хранимых подразделов {count} шт, из прочих {unknown} шт.',
            [
                'count'   => count($topicsHashes, COUNT_RECURSIVE) - count($topicsHashes),
                'unknown' => count($unknownHashes),
                'sec'     => Timers::getExecTime($timer),
            ]
        );

        // Сортируем подразделы по ИД.
        ksort($topicsHashes);
        foreach ($topicsHashes as $group => $hashes) {
            yield $group => $hashes;
        }

        // Возвращаем "прочие" раздачи отдельно.
        yield TopicControl::UnknownHashes => $unknownHashes;
    }

    /**
     * Найти список хранимых подразделов, раздачи которых встречаются в нескольких торрент-клиентах.
     *
     * @return array{}|int[]
     */
    public function getRepeatedSubForums(): array
    {
        $query = '
            SELECT t.forum_id
            FROM (
                SELECT DISTINCT tp.forum_id, tr.client_id
                FROM Topics AS tp
                INNER JOIN Torrents AS tr
                    ON tr.info_hash = tp.info_hash
            ) AS t
            GROUP BY t.forum_id
            HAVING COUNT(1) > 1
        ';

        $forums = $this->db->query($query, [], PDO::FETCH_COLUMN);

        return array_map('intval', $forums);
    }
}
