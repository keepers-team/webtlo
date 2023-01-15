<?php

namespace KeepersTeam\Webtlo\Clients;

use KeepersTeam\Webtlo\Config\V0\TorrentClient;
use KeepersTeam\Webtlo\Config\V0\TorrentClientConfig;
use Psr\Log\LoggerInterface;

final class ClientFactory
{
    /**
     * @par array<string, GenericTorrentClient>
     */
    private array $instances = [];
    /**
     * @var array <string, array{0: SupportedClientType, 1: TorrentClientConfig}>
     */
    private readonly array $clientConfigurations;

    private static function getInstance(
        LoggerInterface $logger,
        SupportedClientType $clientType,
        TorrentClientConfig $torrentClientConfig
    ): GenericTorrentClient {
        $class = "KeepersTeam\Webtlo\Clients\\$clientType->name";
        return new $class($logger, $torrentClientConfig);
    }

    /**
     * @param LoggerInterface $logger
     * @param TorrentClient[] $torrentClients
     */
    public function __construct(private readonly LoggerInterface $logger, array $torrentClients)
    {
        $this->clientConfigurations = array_reduce(
            $torrentClients,
            function ($result, $torrentClient) {
                $result[$torrentClient->id] = [$torrentClient->type, $torrentClient->config];
                return $result;
            },
            []
        );
    }

    public function get(string $id): GenericTorrentClient|false
    {
        if (empty($this->clientConfigurations[$id])) {
            $this->logger->error('Unknown client', ['id' => $id]);
            return false;
        }

        if (empty($this->instances[$id])) {
            list($type, $config) = $this->clientConfigurations[$id];
            $this->logger->info('Instantiating client', ['type' => $type->name, 'id' => $id]);
            $this->instances[$id] = self::getInstance($this->logger, $type, $config);
        }
        return $this->instances[$id];
    }
}
