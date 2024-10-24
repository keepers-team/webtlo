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
     * @param string            $topicHash    Хеш раздачи на форуме.
     * @param string            $clientHash   Хеш раздачи в клиенте.
     * @param string            $name         Название раздачи.
     * @param int|null          $topicId      Ид темы на форуме.
     * @param int               $size         Размер раздачи.
     * @param DateTimeImmutable $added        Дата добавления раздачи в клиенте.
     * @param int|float         $done         Прогресс загрузки (1 = скачана, <1 - качается).
     * @param bool              $paused       Остановлена ли раздача в клиенте.
     * @param bool              $error        Есть ошибка в клиенте.
     * @param string|null       $trackerError Текст ошибки трекера.
     * @param string|null       $comment      Текст комментария раздачи (содержит topicId).
     * @param string|null       $storagePath  Путь хранения раздачи на диске.
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
