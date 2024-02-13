<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

use DateTimeImmutable;

/** Данные о текущих пирах раздачи. */
final class TopicPeers
{
    public function __construct(
        /**
         * @note Due to API inconsistency we've dealing
         *       with either topic identifier or it's hash
         */
        public readonly int|string        $identifier,
        public readonly int               $seeders,
        public readonly int               $leechers,
        public readonly DateTimeImmutable $lastSeeded,
        /** @var ?int[] */
        public readonly ?array            $keepers,
    ) {
    }
}
