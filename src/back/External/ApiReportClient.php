<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Data\KeeperPermissions;
use KeepersTeam\Webtlo\External\ApiReport\Actions;
use Psr\Log\LoggerInterface;

/**
 * Подключение к API отчётов. Получение и отправка данных с авторизацией по ключу.
 */
final class ApiReportClient
{
    use Actions\AccessCheck;
    use Actions\DownloadStaticFile;
    use Actions\ForumTopics;
    use Actions\ForumTopicsPeers;
    use Actions\ForumTree;
    use Actions\KeepersList;
    use Actions\KeepersReports;
    use Actions\KeeperUnseededTopics;
    use Actions\Processor;
    use Actions\SendReportTrait;
    use Actions\TopicsDetails;
    use Actions\TopicsPeers;

    public function __construct(
        protected readonly Client          $client,
        protected readonly ApiCredentials  $auth,
        protected readonly LoggerInterface $logger,
    ) {
        $this->auth->validate();
    }

    /**
     * Проверим статус хранителя.
     * Если хранитель - кандидат, значит ему доступны только отмеченные в профиле подразделы.
     */
    public function getKeeperPermissions(): KeeperPermissions
    {
        try {
            $response = $this->client->get("keeper/{$this->auth->userId}/check_full_permissions");
            $result   = json_decode($response->getBody()->getContents(), true);

            $isCurator   = (bool) ($result['is_curator'] ?? false);
            $isCandidate = (bool) ($result['is_candidate'] ?? false);

            // Если не кандидат, то и ограничений нет.
            if ($isCandidate) {
                $response = $this->client->get("keeper/{$this->auth->userId}/list_subforums");
                $result   = json_decode($response->getBody()->getContents(), true);

                $columns = array_flip($result['columns']);

                $allowed = array_column($result['result'], $columns['subforum_id']);

                $this->logger->info(
                    'Обнаружены ограничения доступа к API отчётов.',
                    ['allowed_subforums' => $allowed]
                );
            }

            return new KeeperPermissions(
                isCurator         : $isCurator,
                isCandidate       : $isCandidate,
                allowedSubsections: $allowed ?? null,
            );
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage());
        }

        return new KeeperPermissions(isCurator: false, isCandidate: false);
    }

    /**
     * Получить список раздач хранителя в указанном подразделе.
     *
     * @return ?array<string, mixed>
     */
    public function getUserKeptReleases(int $subForumId): ?array
    {
        $params = [
            'subforum_id' => $subForumId,
            'columns'     => 'info_hash',
        ];

        try {
            $response = $this->client->get("keeper/{$this->auth->userId}/reports", ['query' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        foreach ($data as $subforum) {
            if ((int) $subforum['subforum_id'] === $subForumId) {
                return $subforum;
            }
        }

        return null;
    }

    /**
     * Записать ошибку в лог.
     *
     * @param array<string, mixed> $params
     */
    private function logException(int $code, string $message, array $params = []): void
    {
        $this->logger->error(
            'Ошибка выполнения запроса',
            ['code' => $code, 'error' => $message]
        );

        if (!empty($params)) {
            $this->logger->debug('Failed params', $params);
        }
    }
}
