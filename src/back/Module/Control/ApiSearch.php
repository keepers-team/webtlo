<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

use Generator;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\External\ApiForumClient;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\TopicsPeers;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Модуль для получения данных о раздачах из API для регулировки.
 */
final class ApiSearch
{
    /**
     * Ид подразделов, данные которых нужно сохранить.
     *
     * @var array{}|int[]
     */
    private array $cacheSubForums = [];

    /**
     * @var array<int, TopicsPeers>
     */
    private array $cachedResponse = [];

    public function __construct(
        private readonly ApiForumClient  $apiForum,
        private readonly ApiReportClient $apiReport,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Получить список давно не сидируемых раздач по заданному подразделу.
     *
     * @return array{}|string[]
     */
    public function getUnseededHashes(int|string $group, int $days): array
    {
        if (is_string($group)) {
            return [];
        }

        $response = $this->apiReport->getKeeperUnseededTopics(forumId: $group, notSeedingDays: $days);
        if ($response instanceof ApiError) {
            $this->logger->error(sprintf('%d %s', $response->code, $response->text));

            return [];
        }

        return $response->getHashes();
    }

    /**
     * @param int[] $forums
     */
    public function setCachedSubForums(array $forums): void
    {
        $this->cacheSubForums = $forums;

        if (count($forums) > 0) {
            $this->logger->debug('Cached subForums', $forums);
        }
    }

    /**
     * Найти в API данные о раздачах для выполнения регулировки.
     *
     * @param int|string $group  Группа искомых раздач. Ид подраздела или "прочие"
     * @param string[]   $hashes Хеши искомых раздач
     */
    public function getTopicsPeersGenerator(int|string $group, array $hashes): Generator
    {
        if (is_int($group)) {
            // Получаем раздачи всего подраздела, кешируем ответ, фильтруем только нужные (быстро).
            return $this->getSubForumTopics(forumId: $group)->filterReleases(hashes: $hashes);
        }

        if ($group === TopicControl::UnknownHashes) {
            // Получаем только искомые раздачи, т.к. не знаем ид подраздела (долго).
            return $this->getApiForumPeers(hashes: $hashes);
        }

        throw new RuntimeException("Неизвестный подраздел: $group");
    }

    /**
     * Запросить в API отчётов раздачи всего подраздела.
     */
    private function getSubForumTopics(int $forumId): TopicsPeers
    {
        $topics = $this->cachedResponse[$forumId] ?? null;
        if ($topics instanceof TopicsPeers) {
            return $topics;
        }

        $response = $this->apiReport->getForumTopicsPeers($forumId);
        if ($response instanceof ApiError) {
            $this->logger->error(sprintf('%d %s', $response->code, $response->text));

            throw new RuntimeException('Не удалось получить данные о пирах раздач подраздела.');
        }

        // Кешируем только те подразделы, которые есть в нескольких торрент-клиентах.
        if (in_array($forumId, $this->cacheSubForums, true)) {
            $this->cachedResponse[$forumId] = $response;
        }

        return $response;
    }

    /**
     * Запросить в API форума искомые раздачи.
     *
     * @param string[] $hashes
     */
    private function getApiForumPeers(array $hashes): Generator
    {
        $response = $this->apiForum->getPeerStats($hashes);

        if ($response instanceof ApiError) {
            $this->logger->error(sprintf('%d %s', $response->code, $response->text));

            throw new RuntimeException('Не удалось получить данные о пирах раздач.');
        }

        foreach ($response->peers as $topic) {
            yield $topic;
        }
    }
}
