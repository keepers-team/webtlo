<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\Data\KeeperPermissions;
use KeepersTeam\Webtlo\External\ApiForumClient;
use KeepersTeam\Webtlo\External\ApiReport\Actions\TopicPeersProcessorInterface;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\Data\ApiError;
use KeepersTeam\Webtlo\External\Data\TopicPeers;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Модуль для получения данных о раздачах из API для регулировки.
 */
final class ApiSearch
{
    private const downloadArchiveThreshold = 20;

    /**
     * Ид подразделов, данные которых нужно сохранить.
     *
     * @var array{}|int[]
     */
    private array $cacheSubForums = [];

    /**
     * @var array<int, TopicPeersProcessorInterface>
     */
    private array $cachedResponse = [];

    public function __construct(
        private readonly ApiForumClient  $apiForum,
        private readonly ApiReportClient $apiReport,
        private readonly LoggerInterface $logger,
    ) {}

    public function __destruct()
    {
        $this->apiReport->cleanupTopicPeersFolder();
    }

    public function getPermissions(): KeeperPermissions
    {
        return $this->apiReport->getKeeperPermissions();
    }

    /**
     * Получить список давно не сидируемых раздач по заданному подразделу.
     *
     * @return array{}|string[]
     */
    public function getUnseededHashes(int|string $group, int $days, int $limit): array
    {
        if (is_string($group)) {
            return [];
        }

        $response = $this->apiReport->getKeeperUnseededTopics(
            forumId       : $group,
            notSeedingDays: max(1, $days),
            limitTopics   : max(1, $limit),
        );
        if ($response instanceof ApiError) {
            $this->logger->error(sprintf('%d %s', $response->code, $response->text));

            return [];
        }

        return $response->getHashes();
    }

    /**
     * @param int[] $subforums
     */
    public function tryDownloadStaticArchive(array $subforums): void
    {
        // Если хранимых подразделов много, загружаем готовый архив.
        if (count($subforums) > self::downloadArchiveThreshold) {
            $this->apiReport->downloadTopicPeersArchive(subforums: $subforums);
        }
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
     *
     * @return iterable<TopicPeers>
     */
    public function getGroupTopicPeersIterator(int|string $group, array $hashes): iterable
    {
        if (is_int($group)) {
            // Получаем раздачи всего подраздела, кешируем ответ, фильтруем только нужные (быстро).
            return $this->getApiReportTopicPeers(forumId: $group)->process(hashes: $hashes);
        }

        if ($group === TopicControl::UnknownHashes) {
            // Получаем только искомые раздачи, т.к. не знаем ид подраздела (долго).
            return $this->getApiForumTopicPeers(hashes: $hashes);
        }

        throw new RuntimeException("Неизвестный подраздел: $group");
    }

    /**
     * Запросить в API отчётов раздачи всего подраздела.
     */
    private function getApiReportTopicPeers(int $forumId): TopicPeersProcessorInterface
    {
        $topics = $this->cachedResponse[$forumId] ?? null;
        if ($topics instanceof TopicPeersProcessorInterface) {
            return $topics;
        }

        $response = $this->apiReport->getSubForumTopicPeers(subForumId: $forumId);
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
     *
     * @return iterable<TopicPeers>
     */
    private function getApiForumTopicPeers(array $hashes): iterable
    {
        $response = $this->apiForum->getPeerStats(topics: $hashes);

        if ($response instanceof ApiError) {
            $this->logger->error(sprintf('%d %s', $response->code, $response->text));

            throw new RuntimeException('Не удалось получить данные о пирах раздач.');
        }

        foreach ($response->peers as $topic) {
            yield $topic;
        }
    }
}
