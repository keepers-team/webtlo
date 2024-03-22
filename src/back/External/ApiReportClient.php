<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\External\ApiReport\Actions\KeepersReports;
use KeepersTeam\Webtlo\External\ApiReport\StaticHelper;
use Psr\Log\LoggerInterface;

final class ApiReportClient
{
    use StaticHelper;
    use KeepersReports;

    protected static int $concurrency = 4;

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
                [$response->getStatusCode(), $response->getReasonPhrase()]
            );
        } catch (GuzzleException $e) {
            $this->logger->error('Ошибка при попытке авторизации в report-api.', [$e->getCode(), $e->getMessage()]);
        }

        return false;
    }

    /**
     * @param int   $forumId
     * @param int[] $topicIds
     * @param int   $status
     * @param bool  $excludeOtherReleases
     * @return ?array
     */
    public function reportKeptReleases(
        int   $forumId,
        array $topicIds,
        int   $status,
        bool  $excludeOtherReleases = false,
    ): ?array {
        $params = [
            'user_id'                             => $this->cred->userId,
            'topic_ids'                           => $topicIds,
            'status'                              => $status,
            'reported_subforum_id'                => $forumId,
            'unreport_other_releases_in_subforum' => $excludeOtherReleases,
        ];

        $this->logger->debug('Fetching page', $params);
        try {
            $response = $this->client->post('releases/set_status', ['json' => $params]);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch page', [$e->getCode(), $e->getMessage(), ...$params]);

            return null;
        }

        $body = $response->getBody()->getContents();
        $this->logger->debug('Response body', [$body]);

        return json_decode($body, true);
    }

    /**
     * @param int $forumId
     * @param int $status
     * @return ?array
     */
    public function setForumStatus(int $forumId, int $status): ?array
    {
        $params = [
            'query' => [
                'keeper_id'   => $this->cred->userId,
                'status'      => $status,
                'subforum_id' => $forumId,
            ],
        ];

        $this->logger->debug('Fetching page', $params);
        try {
            $response = $this->client->post('subforum/set_status', $params);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch page', [$e->getCode(), $e->getMessage(), ...$params]);

            return null;
        }

        $body = $response->getBody()->getContents();
        $this->logger->debug('Response body', [$body]);

        return json_decode($body, true);
    }

    public function getForumReports(int $forumId, array $columns = []): ?array
    {
        $path   = "subforum/$forumId/reports";
        $params = ['columns' => implode(',', $columns)];

        $this->logger->debug('Fetching page', ['path' => $path, ...$params]);
        try {
            $response = $this->client->get($path, ['query' => $params]);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch page', [$e->getCode(), $e->getMessage(), ...$params]);

            return null;
        }

        $body = $response->getBody()->getContents();
        $this->logger->debug(sprintf('Got reports, strlen: %s', strlen($body)));

        return json_decode($body, true);
    }
}
