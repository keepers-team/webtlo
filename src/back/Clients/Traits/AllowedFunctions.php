<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

trait AllowedFunctions
{
    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    protected bool $categoryAddingAllowed = false;

    /** Пауза между добавлением раздач в торрент-клиент, миллисекунды. */
    protected int $torrentAddingSleep = 500;

    public function getTorrentAddingSleep(): int
    {
        return $this->torrentAddingSleep * 1000;
    }

    public function isLabelAddingAllowed(): bool
    {
        return $this->categoryAddingAllowed;
    }
}
