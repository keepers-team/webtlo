<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Module\Action\ClientAction;
use KeepersTeam\Webtlo\Module\Action\ClientApplyOptions;
use KeepersTeam\Webtlo\Storage\Table\Torrents;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Управление раздачами в торрент-клиенте при нажатии кнопок на вкладке "Раздачи".
 */
final class ClientApplyAction
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClientFactory   $clientFactory,
        private readonly Torrents        $tableTorrents,
    ) {}

    /**
     * @param string[] $hashes
     */
    public function process(
        ClientAction       $action,
        array              $hashes,
        int                $selectedClient,
        ClientApplyOptions $params,
    ): void {
        $this->logger->info("Начато выполнение действия '$action->value' для выбранных раздач...");
        $this->logger->debug('Получение хэшей раздач с привязкой к торрент-клиенту...');

        $torrentHashesByClient = $this->tableTorrents->getGroupedByClientTopics(hashes: $hashes);
        if (!count($torrentHashesByClient)) {
            throw new RuntimeException('Не получены данные о выбранных раздачах');
        }

        $this->logger->info(
            'Количество затрагиваемых торрент-клиентов: {client}',
            ['client' => count($torrentHashesByClient)]
        );

        if ($action === ClientAction::Remove && $selectedClient === 0) {
            $this->logger->notice(
                'Не задан фильтр по торрент-клиенту. Выбранные раздачи будут удалены во всех торрент-клиентах.'
            );
        }

        if ($selectedClient > 0) {
            $this->logger->info(
                'Задан фильтр по торрент-клиенту с идентификатором [{filter}].',
                ['filter' => $selectedClient]
            );
        }

        foreach ($torrentHashesByClient as $clientId => $torrentHashes) {
            if (empty($torrentHashes)) {
                continue;
            }

            $clientId = (int) $clientId;

            // Пропускаем раздачи в других клиентах, если задан фильтр.
            if ($selectedClient > 0 && $selectedClient !== $clientId) {
                continue;
            }

            // Получаем и проверяем доступность клиента.
            $client = $this->clientFactory->getClientById(clientId: $clientId);

            // Если клиент недоступен, пропускаем.
            if ($client === null) {
                continue;
            }

            $logRecord = ['tag' => $client->getClientTag(), 'action' => $action->value];

            $response = false;
            switch ($action) {
                case ClientAction::SetLabel:
                    $response = $client->setLabel(torrentHashes: $torrentHashes, label: $params->label);

                    break;
                case ClientAction::Stop:
                    $response = $client->stopTorrents(torrentHashes: $torrentHashes);

                    // Отмечаем в БД изменение статуса раздач.
                    if ($response !== false) {
                        $this->tableTorrents->setTorrentsStatusByHashes(hashes: $torrentHashes, paused: true);
                    }

                    break;
                case ClientAction::Start:
                    $response = $client->startTorrents(torrentHashes: $torrentHashes, forceStart: $params->forceStart);

                    // Отмечаем в БД изменение статуса раздач.
                    if ($response !== false) {
                        $this->tableTorrents->setTorrentsStatusByHashes(hashes: $torrentHashes, paused: false);
                    }

                    break;
                case ClientAction::Remove:
                    $response = $client->removeTorrents(torrentHashes: $torrentHashes, deleteFiles: $params->removeFiles);

                    // Отмечаем в БД удаление раздач.
                    if ($response !== false) {
                        $this->tableTorrents->deleteTorrentsByHashes(hashes: $torrentHashes);
                    }

                    break;
            }

            if ($response === false) {
                $this->logger->warning(
                    "Возникли проблемы при выполнении действия '{action}' для торрент-клиента '{tag}'",
                    $logRecord
                );
            } else {
                $this->logger->info(
                    "Действие '{action}' для торрент-клиента '{tag}' выполнено ({count})",
                    [...$logRecord, 'count' => count($torrentHashes)]
                );
            }
            unset($clientId, $torrentHashes);
        }

        $this->logger->info("Выполнение действия '$action->value' завершено.");
        $this->logger->info('-- DONE --');
    }
}
