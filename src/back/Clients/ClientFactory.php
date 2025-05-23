<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients;

use KeepersTeam\Webtlo\Config\TorrentClientOptions;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Storage\Table\Topics;
use KeepersTeam\Webtlo\Storage\Table\Torrents;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ClientFactory
{
    public function __construct(
        private readonly TorrentClients  $options,
        private readonly LoggerInterface $logger,
        private readonly Topics          $topics,
        private readonly Torrents        $torrents,
    ) {}

    /**
     * Собрать подключение к торрент-клиенту из параметров.
     */
    public function getClient(TorrentClientOptions $clientOptions): ClientInterface
    {
        // Параметры для основных клиентов.
        $params = [$this->logger, $clientOptions];

        // Расширенные параметры для поиска раздач в локальной БД.
        $extended = [...$params, $this->topics, $this->torrents];

        return match ($clientOptions->type) {
            ClientType::Qbittorrent  => new Qbittorrent(...$extended),
            ClientType::Deluge       => new Deluge(...$params),
            ClientType::Flood        => new Flood(...$params),
            ClientType::Rtorrent     => new Rtorrent(...$params),
            ClientType::Transmission => new Transmission(...$params),
            ClientType::Utorrent     => new Utorrent(...$extended),
        };
    }

    /**
     * Попробовать подключится к торрент-клиенту по clientId.
     */
    public function getClientById(int $clientId): ?ClientInterface
    {
        $clientOptions = $this->options->getClientOptions(clientId: $clientId);

        if ($clientOptions === null) {
            $this->logger->warning(
                'В настройках нет данных о торрент-клиенте с идентификатором [{tag}]',
                ['tag' => $clientId]
            );

            return null;
        }

        try {
            $client = $this->getClient($clientOptions);

            // Проверка доступности торрент-клиента.
            if (!$client->isOnline()) {
                throw new RuntimeException('Не удалось авторизоваться.');
            }

            return $client;
        } catch (RuntimeException $e) {
            $this->logger->error(
                'Торрент-клиент {tag} в данный момент недоступен. {error}',
                ['tag' => $clientOptions->name, 'error' => $e->getMessage()]
            );
        }

        return null;
    }

    /**
     * Коннект с клиентом, из параметров клиента из UI.
     *
     * @param array<string, mixed> $options
     */
    public function fromFrontProperties(array $options): ClientInterface
    {
        $type = ClientType::tryFrom((string) $options['type']);

        if ($type === null) {
            $this->logger->error('Unknown client type.', $options);

            throw new RuntimeException('Unknown client type');
        }

        return $this->getClient(TorrentClientOptions::fromFrontProperties($options));
    }
}
