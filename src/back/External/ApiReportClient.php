<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use DateTimeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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
    use Actions\ForumTopicsPeers;
    use Actions\ForumTopics;
    use Actions\KeepersReports;
    use Actions\KeeperUnseededTopics;
    use Actions\Processor;

    public function __construct(
        private readonly Client          $client,
        private readonly ApiCredentials  $auth,
        private readonly LoggerInterface $logger,
    ) {}

    public function checkAccess(): bool
    {
        try {
            $response = $this->client->get('info/statuses');
            $statuses = json_decode($response->getBody()->getContents(), true);

            return !empty($statuses);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $this->logger->error(
                'Не удалось авторизоваться в report-api. Проверьте ключи доступа.',
                ['code' => $response->getStatusCode(), 'error' => $response->getReasonPhrase()]
            );
        } catch (GuzzleException $e) {
            $this->logger->error(
                'Ошибка при попытке авторизации в report-api.',
                ['code' => $e->getCode(), 'error' => $e->getMessage()]
            );
        }

        return false;
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
     * @param int[] $topicIds
     *
     * @return ?array<string, int>
     */
    public function reportKeptReleases(
        int               $forumId,
        array             $topicIds,
        int               $status,
        DateTimeInterface $reportDate,
        bool              $excludeOtherReleases = false,
    ): ?array {
        $params = [
            'keeper_id'                           => $this->auth->userId,
            'topic_ids'                           => $topicIds,
            'status'                              => $status,
            'last_update_time'                    => $reportDate->format(DateTimeInterface::ATOM),
            'reported_subforum_id'                => $forumId,
            'unreport_other_releases_in_subforum' => $excludeOtherReleases,
        ];

        try {
            $response = $this->client->post('releases/set_status', ['json' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        $body = $response->getBody()->getContents();

        return json_decode($body, true);
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
     * Задать статус хранения подраздела.
     */
    public function setForumStatus(int $forumId, int $status, string $appVersion = ''): bool
    {
        $params = [
            'keeper_id'   => $this->auth->userId,
            'status'      => $status,
            'subforum_id' => $forumId,
            'comment'     => $appVersion,
        ];

        try {
            $response = $this->client->post('subforum/set_status', ['query' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return false;
        }

        $body = json_decode($response->getBody()->getContents(), true);

        return (bool) ($body['result'] ?? false);
    }

    /**
     * Задать статус хранения подразделов, и пометить остальные как более не хранимые.
     *
     * @param int[] $forumIds
     *
     * @return array<string, mixed>
     */
    public function setForumsStatus(array $forumIds, int $status, string $appVersion, bool $unsetOtherForums): array
    {
        $params = [
            'keeper_id'             => $this->auth->userId,
            'status'                => $status,
            'subforum_id'           => implode(',', array_filter($forumIds)),
            'comment'               => $appVersion,
            'unset_other_subforums' => $unsetOtherForums,
        ];

        try {
            // POST запрос с GET параметрами.
            $response = $this->client->post('subforum/set_status_bulk', ['query' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return ['result' => $e->getMessage()];
        }

        $body = json_decode($response->getBody()->getContents(), true);

        return $body ?: ['result' => 'unknown'];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function sendCustomData(array $data): void
    {
        try {
            $this->client->post("custom_data/{$this->auth->userId}", ['json' => $data]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $data);
        }
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
