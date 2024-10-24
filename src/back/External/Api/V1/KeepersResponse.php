<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/**
 * Данные всех известных хранителей из API.
 */
final class KeepersResponse
{
    /**
     * @param DateTimeImmutable      $updateTime Дата получения данных.
     * @param array<int, KeeperData> $keepers    Ид хранителя => Данные о нём.
     */
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        public readonly array             $keepers,
    ) {}

    public function getKeeperInfo(int $keeperId): ?KeeperData
    {
        return $this->keepers[$keeperId] ?? null;
    }
}
