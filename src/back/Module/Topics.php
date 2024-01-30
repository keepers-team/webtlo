<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Legacy\Db;
use PDO;

/** Методы для работы с раздачами в хранимых подразделах. */
final class Topics
{
    /** Допустимые статус раздач */
    public const VALID_STATUSES = [0, 2, 3, 8, 10];

    /** Поиск в БД ид раздач, по хешу */
    public static function getTopicsIdsByHashes(array $hashes, int $chunkSize = 500): array
    {
        $result = [];
        $hashes = array_chunk($hashes, $chunkSize);
        foreach ($hashes as $chunk) {
            $search = KeysObject::create($chunk);
            $topics = Db::query_database(
                "SELECT info_hash, id topic_id FROM Topics WHERE info_hash IN ($search->keys)",
                $search->values,
                true,
                PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE
            );
            if (!empty($topics)) {
                $result[] = $topics;
            }
        }
        return array_merge(...$result);
    }

    /** Удаление раздач по списку их ИД */
    public static function deleteTopicsByIds(array $topics): void
    {
        $topics = array_chunk($topics, 500);
        foreach ($topics as $chunk) {
            $delete = KeysObject::create($chunk);
            Db::query_database(
                "DELETE FROM Topics WHERE id IN ($delete->keys)",
                $delete->values
            );
            unset($chunk, $delete);
        }
    }
}
