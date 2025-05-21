<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Data\Keeper;

/**
 * Данные всех известных хранителей из API.
 */
final class KeepersResponse
{
    /**
     * @param DateTimeImmutable  $updateTime дата получения данных
     * @param array<int, Keeper> $keepers    ид хранителя => Данные о нём
     */
    public function __construct(
        public readonly DateTimeImmutable $updateTime,
        public readonly array             $keepers,
    ) {}

    public function getKeeperInfo(int $keeperId): ?Keeper
    {
        return $this->keepers[$keeperId] ?? null;
    }
}
