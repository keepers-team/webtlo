<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Helper;

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
        $clients = $this->getNameSorted();

        return array_combine(
            array_keys($clients),
            array_column($clients, 'name'),
        );
    }

    public function count(): int
    {
        return count($this->clients);
    }

    /**
     * Получить список клиентов, отсортированный по введённому имени (tag).
     *
     * @return TorrentClientOptions[]
     */
    public function getNameSorted(): array
    {
        $clients = $this->clients;

        uasort($clients, static function(TorrentClientOptions $a, TorrentClientOptions $b) {
            return strnatcasecmp(
                Helper::prepareCompareString($a->tag),
                Helper::prepareCompareString($b->tag),
            );
        });

        return $clients;
    }
}
