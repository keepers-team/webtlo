<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

trait AllowedFunctions
{
    public function getTorrentAddingSleep(): int
    {
        return ($this->torrentAddingSleep ?? 500) * 1000;
    }

    public function isLabelAddingAllowed(): bool
    {
        return $this->categoryAddingAllowed ?? false;
    }
}
