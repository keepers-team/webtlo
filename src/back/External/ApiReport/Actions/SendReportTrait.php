<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use DateTimeInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;

/**
 * Методы связанные с отправкой отчётов о хранимом в API.
 */
trait SendReportTrait
{
    /**
     * Отправить список ид хранимых раздач конкретного подраздела.
     *
     * @param int[] $topicIds
     *
     * @return ?array<mixed>
     */
    public function reportKeptReleases(
        int               $forumId,
        array             $topicIds,
        int               $status,
        DateTimeInterface $reportDate,
        bool              $excludeOther = false,
    ): ?array {
        $params = [
            'keeper_id'                           => $this->auth->userId,
            'topic_ids'                           => $topicIds,
            'status'                              => $status,
            'last_update_time'                    => $reportDate->format(DateTimeInterface::ATOM),
            'reported_subforum_id'                => $forumId,
            'unreport_other_releases_in_subforum' => $excludeOther,
        ];

        try {
            $response = $this->client->post('releases/set_status', ['json' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        return $this->decodeReportResponse($response->getBody()->getContents(), $params);
    }

    /**
     * Отправить список хранимых хешей без указания конкретного подраздела.
     *
     * @param string[] $topicHashes
     *
     * @return ?array<mixed>
     */
    public function reportKeptReleasesHashes(
        array             $topicHashes,
        int               $status,
        DateTimeInterface $reportDate,
        bool              $excludeOther = false,
    ): ?array {
        $params = [
            'keeper_id'        => $this->auth->userId,
            'topic_hashes'     => $topicHashes,
            'status'           => $status,
            'last_update_time' => $reportDate->format(DateTimeInterface::ATOM),
        ];

        // По умолчанию - 60 дней, в соответствии с регламентом.
        $params['unreport_older_than'] = 'P60D';

        /**
         * Если указано снять отметку хранения с раздач, которые явно не переданы,
         * то отправляем интервал 2 мин, чтобы все части отчёта успели дойти.
         *
         * Раздачи, старше этого периода будут исключены из хранимых.
         */
        if ($excludeOther) {
            $params['unreport_older_than'] = 'PT2M';
        }

        try {
            $response = $this->client->post('releases/set_status_by_hash', ['json' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        return $this->decodeReportResponse($response->getBody()->getContents(), $params);
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

        $body = $this->decodeReportResponse($response->getBody()->getContents(), $params);

        return (bool) ($body['result'] ?? false);
    }

    /**
     * Задать статус хранения подразделов, и пометить остальные как более не хранимые.
     *
     * @param int[] $forumIds
     *
     * @return ?array<mixed>
     */
    public function setForumsStatus(array $forumIds, int $status, string $appVersion, bool $unsetOtherForums): ?array
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

            return null;
        }

        return $this->decodeReportResponse($response->getBody()->getContents(), $params);
    }

    /**
     * Триггер для API отчётов, для автоматического определения статуса хранимых подразделов
     * на основании переданных хешей хранимых раздач.
     *
     * @return ?array<mixed>
     */
    public function setForumsStatusAuto(): ?array
    {
        $params = [
            'keeper_id'           => $this->auth->userId,
            'ignore_non_reported' => true, // Указание API не учитывать раздачи "по сидированию"
        ];

        try {
            // POST запрос с GET параметрами.
            $response = $this->client->post('subforum/set_status_auto', ['query' => $params]);
        } catch (GuzzleException $e) {
            $this->logException($e->getCode(), $e->getMessage(), $params);

            return null;
        }

        $body = $this->decodeReportResponse($response->getBody()->getContents(), $params);
        if ($body === null) {
            return null;
        }

        unset($body['dry_run'], $body['details']);

        return $body;
    }

    /**
     * Отправка дополнительной информации о настройках ПО в API.
     *
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
     * @param array<string, mixed> $params
     *
     * @return ?array<mixed>
     */
    private function decodeReportResponse(string $body, array $params): ?array
    {
        try {
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logException(0, $e->getMessage(), $params);

            return null;
        }

        if (!is_array($result)) {
            $this->logException(0, 'Некорректный ответ API', $params);

            return null;
        }

        return $result;
    }
}
