<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

/**
 * Данные о текущих пирах раздачи.
 */
final class TopicPeers
{
    /**
     * @param int    $id       Ид раздачи
     * @param string $hash     Хеш раздачи
     * @param int    $seeders  количество сидов
     * @param int    $leechers Количество личей
     * @param int    $keepers  Количество сидов-хранителей
     */
    public function __construct(
        public readonly int    $id,
        public readonly string $hash,
        public readonly int    $seeders,
        public readonly int    $leechers,
        public readonly int    $keepers,
    ) {
    }
}
