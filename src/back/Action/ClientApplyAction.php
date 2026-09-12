<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Clients\ClientInterface;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Module\Action\ClientAction;
use KeepersTeam\Webtlo\Module\Action\ClientApplyOptions;
use KeepersTeam\Webtlo\Storage\Table\Torrents;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Управление раздачами в торрент-клиенте при нажатии кнопок на вкладке "Раздачи".
 */
final class ClientApplyAction
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClientFactory   $clientFactory,
        private readonly SubForums       $subForums,
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

        $groupByClient = $this->tableTorrents->getGroupedTopics(hashes: $hashes);
        if (!count($groupByClient)) {
            throw new RuntimeException('Не удалось найти данные о выбранных раздачах');
        }

        $this->logger->info(
            'Количество затрагиваемых торрент-клиентов: {client}',
            ['client' => count($groupByClient)]
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

        foreach ($groupByClient as $clientId => $groupBySubForum) {
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

            $groupBySubForum = $this->resolveClientHashes(
                client         : $client,
                clientId       : $clientId,
                groupBySubForum: $groupBySubForum,
            );

            $logRecord = ['tag' => $client->getClientTag(), 'action' => $action->value];

            foreach ($groupBySubForum as $subForumId => $hashesByTopic) {
                if (empty($hashesByTopic)) {
                    continue;
                }

                $topicHashes  = array_keys($hashesByTopic);
                $clientHashes = array_values($hashesByTopic);

                $response = false;
                switch ($action) {
                    case ClientAction::SetLabel:
                        // Если метка не задана в запросе, пробуем найти в настройках по ид подраздела.
                        $label = $this->findLabel(params: $params, subForumId: $subForumId);

                        $logRecord['forumId'] = $subForumId;
                        $logRecord['label']   = $label;

                        $response = $client->setLabel(torrentHashes: $clientHashes, label: $label);

                        break;
                    case ClientAction::Stop:
                        $response = $client->stopTorrents(torrentHashes: $clientHashes);

                        // Отмечаем в БД изменение статуса раздач.
                        if ($response !== false) {
                            $this->tableTorrents->setTorrentsStatusByHashes(
                                hashes   : $topicHashes,
                                clientId : $clientId,
                                paused   : true
                            );
                        }

                        break;
                    case ClientAction::Start:
                        $response = $client->startTorrents(
                            torrentHashes: $clientHashes,
                            forceStart   : $params->forceStart
                        );

                        // Отмечаем в БД изменение статуса раздач.
                        if ($response !== false) {
                            $this->tableTorrents->setTorrentsStatusByHashes(
                                hashes   : $topicHashes,
                                clientId : $clientId,
                                paused   : false
                            );
                        }

                        break;
                    case ClientAction::Remove:
                        $response = $client->removeTorrents(
                            torrentHashes: $clientHashes,
                            deleteFiles  : $params->removeFiles
                        );

                        // Отмечаем в БД удаление раздач.
                        if ($response !== false) {
                            $this->tableTorrents->deleteTorrentsByHashes(
                                hashes  : $topicHashes,
                                clientId: $clientId
                            );
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
                        [...$logRecord, 'count' => count($clientHashes)]
                    );
                }
            }
        }

        $this->logger->info("Выполнение действия '$action->value' завершено.");
        $this->logger->info('-- DONE --');
    }

    /**
     * Получить неизвестные идентификаторы раздач из клиента и исключить отсутствующие раздачи.
     *
     * @param array<int, array<string, string>> $groupBySubForum
     *
     * @return array<int, array<string, string>>
     */
    private function resolveClientHashes(
        ClientInterface $client,
        int             $clientId,
        array           $groupBySubForum,
    ): array {
        $unknownCount = 0;
        foreach ($groupBySubForum as $hashesByTopic) {
            $unknownCount += count(array_filter($hashesByTopic, static fn(string $hash): bool => $hash === ''));
        }

        if ($unknownCount === 0) {
            return $groupBySubForum;
        }

        try {
            $clientTorrents = $client->getTorrents(['simple' => true]);
        } catch (Throwable) {
            $clientTorrents = null;
        }

        $resolvedHashes  = [];
        $unresolvedCount = 0;
        foreach ($groupBySubForum as $subForumId => $hashesByTopic) {
            foreach ($hashesByTopic as $topicHash => $clientHash) {
                if ($clientHash !== '') {
                    continue;
                }

                $clientHash = $clientTorrents?->getTorrent(hash: $topicHash)?->clientHash ?? '';
                if ($clientHash === '') {
                    unset($groupBySubForum[$subForumId][$topicHash]);
                    ++$unresolvedCount;

                    continue;
                }

                $groupBySubForum[$subForumId][$topicHash] = $clientHash;
                $resolvedHashes[$topicHash]               = $clientHash;
            }
        }

        if ($resolvedHashes !== []) {
            $this->tableTorrents->setClientHashes(hashesByTopic: $resolvedHashes, clientId: $clientId);
        }

        if ($unresolvedCount > 0) {
            $this->logger->warning(
                'Не удалось определить идентификаторы раздач в торрент-клиенте {tag}. Пропущено: {count}',
                ['tag' => $client->getClientTag(), 'count' => $unresolvedCount]
            );
        }

        return $groupBySubForum;
    }

    private function findLabel(ClientApplyOptions $params, int $subForumId): string
    {
        // Метка есть в запросе, значит её и используем.
        if ($params->label !== null) {
            return $params->label;
        }

        // Текущий разворот подразумевает массовое присвоение меток.
        if ($params->listingType === null || $params->listingType->allowMassLabelSet()) {
            // Пробуем найти метку по подразделу.
            return $this->subForums->getSubForum(subForumId: $subForumId)->label ?? '';
        }

        // Пробуем присвоить метку по умолчанию или пустую.
        return $params->listingType->getDefaultLabel();
    }
}
