<?php

namespace KeepersTeam\Webtlo\Config\V0;

final class Download
{
    public function __construct(
        public readonly string $saveDir = 'temp',
        public readonly bool $saveSubDir = false,
        public readonly bool $addRetracker = false,
    ) {
    }
}
