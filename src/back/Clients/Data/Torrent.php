<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Data;

use DateTimeImmutable;

/**
 * Данные одной раздачи в торрент клиенте.
 */
final class Torrent
{
    /**
     * @param string            $topicHash    хеш раздачи на форуме
     * @param string            $clientHash   хеш раздачи в клиенте
     * @param string            $name         название раздачи
     * @param null|int          $topicId      ид темы на форуме
     * @param int               $size         размер раздачи
     * @param DateTimeImmutable $added        дата добавления раздачи в клиенте
     * @param int|float         $done         прогресс загрузки (1 = скачана, <1 - качается)
     * @param bool              $paused       остановлена ли раздача в клиенте
     * @param bool              $error        есть ошибка в клиенте
     * @param null|string       $trackerError текст ошибки трекера
     * @param null|string       $comment      текст комментария раздачи (содержит topicId)
     * @param null|string       $storagePath  путь хранения раздачи на диске
     */
    public function __construct(
        public readonly string            $topicHash,
        public readonly string            $clientHash,
        public readonly string            $name,
        public readonly ?int              $topicId,
        public readonly int               $size,
        public readonly DateTimeImmutable $added,
        public readonly int|float         $done,
        public readonly bool              $paused,
        public readonly bool              $error = false,
        public readonly ?string           $trackerError = null,
        public readonly ?string           $comment = null,
        public readonly ?string           $storagePath = null,
    ) {}
}
