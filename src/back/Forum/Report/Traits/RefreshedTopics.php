<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Forum\Report\Traits;

use KeepersTeam\Webtlo\DTO\KeysObject;

trait RefreshedTopics
{
    /**
     * Найти список обновлённых раздач, для отправки отдельно отчёта.
     *
     * @param int[] $forums
     * @return ?array<int, array<string, int|string>>
     */
    public function getRefreshedTopics(array $forums): ?array
    {
        $forums = KeysObject::create($forums);

        $query = "
            SELECT tr.topic_id AS topic_id,
                   tr.info_hash,
                   tp.reg_time AS registered
            FROM TopicsUnregistered AS tu
                INNER JOIN Torrents AS tr ON tu.info_hash = tr.info_hash
                INNER JOIN Topics AS tp ON tp.id = tr.topic_id
            WHERE tr.done = '1.0'
              AND tp.forum_id IN ($forums->keys)
        ";

        $data = $this->db->query($query, $forums->values);

        if (empty($data)) {
            return null;
        }

        return $data;
    }
}
