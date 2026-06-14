<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Data;

use JsonSerializable;

/**
 * Параметры раздачи, которую требуется скачать и добавить в торрент-клиент.
 */
final class DownloadedTopic implements JsonSerializable
{
    public function __construct(
        public readonly string $hash,
        public readonly int    $topicId,
        public readonly string $filePath,
    ) {}

    /**
     * @return array<string, int|string>
     */
    public function jsonSerialize(): array
    {
        return [
            'topicId' => $this->topicId,
            'hash'    => $this->hash,
        ];
    }
}
