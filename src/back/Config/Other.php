<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class Other
{
    public function __construct(
        public readonly string $logLevel,
        public readonly bool   $uiSaveSelectedSection,
        public readonly bool   $uiAutoApplyFilter,
        public readonly string $uiTheme,
    ) {}
}
