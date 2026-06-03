<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Table;

use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Storage\KeysObject;

final class TopicsExcluded
{
    public function __construct(
        private readonly ConnectionInterface $con
    ) {}

    /**
     * Добавить или удалить раздачи из списка исключений.
     *
     * @param string[] $hashes
     */
    public function manageTopics(array $hashes, bool $exclude): void
    {
        $hashes = array_chunk($hashes, 500);
        foreach ($hashes as $chunk) {
            $object = KeysObject::create(data: $chunk);

            if ($exclude) {
                $placeholder = $object->getInsertPlaceholder();

                $sql = "INSERT INTO TopicsExcluded (info_hash) VALUES $placeholder";
            } else {
                $sql = "DELETE FROM TopicsExcluded WHERE info_hash IN ($object->keys)";
            }

            $this->con->executeStatement(sql: $sql, param: $object->values);
        }
    }
}
