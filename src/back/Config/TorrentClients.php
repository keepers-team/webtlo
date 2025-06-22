<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Используемые торрент-клиенты и параметры подключения к ним.
 */
final class TorrentClients
{
    /**
     * @param array<int, TorrentClientOptions> $clients
     */
    public function __construct(
        public readonly array $clients,
    ) {}

    /**
     * Поиск параметров подключения к клиенту по ид.
     */
    public function getClientOptions(int $clientId): ?TorrentClientOptions
    {
        return $this->clients[$clientId] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function getClientsNames(): array
    {
        return array_combine(
            array_keys($this->clients),
            array_column($this->clients, 'name'),
        );
    }

    public function count(): int
    {
        return count($this->clients);
    }
}
