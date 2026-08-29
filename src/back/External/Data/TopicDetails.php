<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Enum\KeepingPriority;
use KeepersTeam\Webtlo\Enum\TorrentStatus;

/**
 * Дополнительные сведения о раздаче.
 */
final class TopicDetails
{
    /**
     * @param int               $id            ид раздачи
     * @param string            $hash          хеш раздачи
     * @param int               $forumId       ид подраздела на форуме
     * @param int               $poster        ид автора раздачи
     * @param int               $size          размер раздачи в байтах
     * @param DateTimeImmutable $registered    дата регистрации
     * @param TorrentStatus     $status        статус раздачи на форуме
     * @param KeepingPriority   $priority      приоритет хранения
     * @param int               $seeders       количество сидов на раздаче
     * @param string            $title         наименование
     * @param ?TopicDetails     $actualVersion актуальная версия раздачи, если есть
     */
    public function __construct(
        public readonly int               $id,
        public readonly string            $hash,
        public readonly int               $forumId,
        public readonly int               $poster,
        public readonly int               $size,
        public readonly DateTimeImmutable $registered,
        public readonly TorrentStatus     $status,
        public readonly KeepingPriority   $priority,
        public readonly int               $seeders,
        public readonly string            $title,
        public readonly ?self     $actualVersion = null,
    ) {}
}
