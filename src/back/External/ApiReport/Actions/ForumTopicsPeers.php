<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\Helper;
use Throwable;

trait ForumTopicsPeers
{
    /** Путь к временному каталогу с распакованными данными. */
    private ?string $gzipTopicPeersFolder = null;

    /**
     * Загрузка и распаковка статичного архива со всеми хранимыми раздачами всех хранителей.
     *
     * @param ?int[] $subforums
     */
    public function downloadTopicPeersArchive(?array $subforums = null): void
    {
        $this->gzipTopicPeersFolder = $this->downloadStaticFile(
            filename : 'public_seeding-control.tar',
            subforums: $subforums,
        );
    }

    /**
     * Получить данные для регулировки по подразделу.
     * Из csv (если он есть) или из API.
     */
    public function getSubForumTopicPeers(int $subForumId): TopicPeersProcessorInterface|ApiError
    {
        return $this->tryGetCsvPeersProcessor(subForumId: $subForumId)
            ?? $this->getApiPeersProcessor(subForumId: $subForumId);
    }

    private function tryGetCsvPeersProcessor(int $subForumId): ?TopicPeersProcessorInterface
    {
        // Архив не загружен, выходим сразу.
        if ($this->gzipTopicPeersFolder === null) {
            return null;
        }

        try {
            if ($csv = $this->getCsvReader(folderPath: $this->gzipTopicPeersFolder, forumId: $subForumId)) {
                return new CsvTopicPeersProcessor(csv: $csv);
            }
        } catch (Throwable $e) {
            $this->logger->warning('CSV processing error: ' . $e->getMessage());
        }

        return null;
    }

    private function getApiPeersProcessor(int $subForumId): TopicPeersProcessorInterface|ApiError
    {
        try {
            $response = $this->client->get(uri: "subforum/$subForumId/pvc", options: [
                'query' => ['columns' => 'info_hash,seeders,leechers,keeper_seeders'],
            ]);
        } catch (GuzzleException $e) {
            return ApiError::fromHttpCode(code: $e->getCode());
        }

        $result = self::decodeResponse(logger: $this->logger, response: $response);
        if ($result instanceof ApiError) {
            return $result;
        }

        return new ApiTopicPeersProcessor(
            columns : $result['columns'],
            releases: $result['releases'],
        );
    }

    /**
     * Удалить статические файлы, если они есть.
     */
    public function cleanupTopicPeersFolder(): void
    {
        if ($this->gzipTopicPeersFolder !== null) {
            Helper::removeDirRecursive(path: $this->gzipTopicPeersFolder);

            $this->gzipTopicPeersFolder = null;
        }
    }
}
