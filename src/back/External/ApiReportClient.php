<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\External\ApiReport\Actions;
use KeepersTeam\Webtlo\External\ApiReport\StaticHelper;
use Psr\Log\LoggerInterface;

/**
 * Подключение к API отчётов. Получение и отправка данных с авторизацией по ключу.
 */
final class ApiReportClient
{
    use StaticHelper;
    use Actions\KeepersReports;
    use Actions\Processor;
    use Actions\ReportForumTopics;

    public function __construct(
        private readonly Client          $client,
        private readonly Credentials     $cred,
        private readonly LoggerInterface $logger,
    ) {
    }

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
     * @param int   $forumId
     * @param int[] $topicIds
     * @param int   $status
     * @param bool  $excludeOtherReleases
     * @return ?array<string, int>
     */
    public function reportKeptReleases(
        int   $forumId,
        array $topicIds,
        int   $status,
        bool  $excludeOtherReleases = false,
    ): ?array {
        $params = [
            'keeper_id'                           => $this->cred->userId,
            'topic_ids'                           => $topicIds,
            'status'                              => $status,
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
     * @param int $subForumId
     * @return ?array<string, mixed>
     */
    public function getUserKeptReleases(int $subForumId): ?array
    {
        $params = [
            'subforum_id' => $subForumId,
            'columns'     => 'info_hash',
        ];

        try {
            $response = $this->client->get("keeper/{$this->cred->userId}/reports", ['query' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        foreach ($data as $subforum) {
            if ((int)$subforum['subforum_id'] === $subForumId) {
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
            'keeper_id'   => $this->cred->userId,
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

        return (bool)($body['result'] ?? false);
    }

    /**
     * Задать статус хранения подразделов, и пометить остальные как более не хранимые
     *
     * @param int[]  $forumIds
     * @param int    $status
     * @param string $appVersion
     * @param bool   $unsetOtherForums
     * @return array<string, mixed>
     */
    public function setForumsStatus(array $forumIds, int $status, string $appVersion, bool $unsetOtherForums): array
    {
        $params = [
            'keeper_id'             => $this->cred->userId,
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
     * @return void
     */
    public function sendCustomData(array $data): void
    {
        try {
            $this->client->post("custom_data/{$this->cred->userId}", ['json' => $data]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $data);
        }
    }

    /**
     * @param int      $forumId
     * @param string[] $columns
     * @return ?array<string, mixed>
     */
    public function getForumReports(int $forumId, array $columns = []): ?array
    {
        $params = ['columns' => implode(',', $columns)];

        try {
            $response = $this->client->get("subforum/$forumId/reports", ['query' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        $body = $response->getBody()->getContents();

        return json_decode($body, true);
    }

    /**
     * Записать ошибку в лог.
     *
     * @param int                  $code
     * @param string               $message
     * @param array<string, mixed> $params
     * @return void
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
