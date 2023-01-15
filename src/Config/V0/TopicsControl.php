<?php

namespace KeepersTeam\Webtlo\Config\V0;

final class TopicsControl
{
    public function __construct(
        public readonly int $peers = 10,
        public readonly bool $unaddedSubsections = false,
        public readonly bool $leechers = false,
        public readonly bool $noLeechers = true,
    ) {
    }
}
