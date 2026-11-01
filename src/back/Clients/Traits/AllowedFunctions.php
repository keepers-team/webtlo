<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

use KeepersTeam\Webtlo\Clients\Data\UnsupportedOperationException;

trait AllowedFunctions
{
    /** Пауза между добавлением раздач в торрент-клиент, миллисекунды. */
    protected int $torrentAddingSleep = 500;

    /** Позволяет ли клиент присваивать раздаче категорию при добавлении. */
    protected bool $categoryAddingAllowed = false;

    /** Позволяет ли клиент менять путь хранения содержимого раздачи. */
    protected bool $locationChangeAllowed = false;

    public function getTorrentAddingSleep(): int
    {
        return $this->torrentAddingSleep * 1000;
    }

    public function isLabelAddingAllowed(): bool
    {
        return $this->categoryAddingAllowed;
    }

    public function isLocationChangeAllowed(): bool
    {
        return $this->locationChangeAllowed;
    }

    public function setTorrentsLocation(array $torrentHashes, string $savePath): bool
    {
        throw new UnsupportedOperationException(
            sprintf(
                'Клиент %s не поддерживает изменение пути хранения раздачи.',
                $this->getClientTypeName()
            )
        );
    }
}
