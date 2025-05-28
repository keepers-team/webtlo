<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

final class TorrentClients
{
    /**
     * @param array<int, TorrentClientOptions> $clients
     */
    public function __construct(
        public readonly array $clients,
    ) {}

    public function getClientOptions(int $clientId): ?TorrentClientOptions
    {
        return $this->clients[$clientId] ?? null;
    }

    public function count(): int
    {
        return count($this->clients);
    }
}
