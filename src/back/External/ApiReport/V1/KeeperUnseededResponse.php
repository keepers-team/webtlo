<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\V1;

use DateTimeImmutable;

final class KeeperUnseededResponse
{
    /**
     * @param int                 $subForumId Ид подраздела
     * @param int                 $totalCount Количество хранимых раздач в подразделе
     * @param string[]            $columns
     * @param array<int, mixed>[] $releases
     * @param DateTimeImmutable   $cacheTime  Дата хеширования ответа
     */
    public function __construct(
        public readonly int               $subForumId,
        public readonly int               $totalCount,
        public readonly DateTimeImmutable $cacheTime,
        private readonly array            $columns,
        private readonly array            $releases,
    ) {}

    /**
     * Найти хеши раздач, которые не сидировались более заданного количества дней.
     *
     * @return string[]
     */
    public function getHashes(): array
    {
        $hashColumn = array_flip($this->columns)['info_hash'];

        return array_column($this->releases, $hashColumn);
    }
}
