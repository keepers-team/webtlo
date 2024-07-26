<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use Generator;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\DesiredStatusChange;
use KeepersTeam\Webtlo\External\Data\TopicPeers;
use KeepersTeam\Webtlo\Timers;
use PDO;
use Psr\Log\LoggerInterface;

final class Control
{
    public const UnknownHashes = 'UnknownHashes';

    public function __construct(
        private readonly TopicControl    $options,
        private readonly DB              $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getStoredHashes(int $torrentClientID, KeysObject $forums, Torrents $torrents): Generator
    {
        Timers::start("get_topics_$torrentClientID");

        $unknownHashes = $torrents->getHashes();

        $hashesChunks = array_chunk($unknownHashes, 999);

        $topicsHashes = [];
        foreach ($hashesChunks as $chunk) {
            $chunk = KeysObject::create($chunk);

            $query = "
                SELECT forum_id, info_hash
                FROM Topics
                WHERE
                    info_hash IN ($chunk->keys)
                    AND forum_id IN ($forums->keys)
            ";

            $result = $this->db->query(
                $query,
                array_merge($chunk->values, $forums->values),
                PDO::FETCH_GROUP | PDO::FETCH_COLUMN,
            );

            foreach ($result as $forumId => $hashes) {
                // Найденные хеши вычитаем из общей кучи.
                $unknownHashes = array_diff($unknownHashes, $hashes);

                $topicsHashes[$forumId][] = $hashes;
            }
        }

        $topicsHashes = array_map(fn($el) => array_merge(...$el), $topicsHashes);

        $this->logger->info(
            'Поиск раздач в БД завершён за {sec}. Найдено раздач из хранимых подразделов {count} шт, из прочих {unknown} шт.',
            [
                'count'   => count($topicsHashes, COUNT_RECURSIVE) - count($topicsHashes),
                'unknown' => count($unknownHashes),
                'sec'     => Timers::getExecTime("get_topics_$torrentClientID"),
            ]
        );

        // Сортируем подразделы по ИД.
        ksort($topicsHashes);
        foreach ($topicsHashes as $group => $hashes) {
            yield $group => $hashes;
        }

        // Возвращаем "прочие" раздачи отдельно.
        yield self::UnknownHashes => $unknownHashes;
    }

    /**
     * Найти список хранимых подразделов, раздачи который встречаются в нескольких торрент-клиентах.
     *
     * @return array{}|int[]
     */
    public function getRepeatedSubForums(): array
    {
        $query = '
            SELECT t.forum_id
            FROM (
                SELECT DISTINCT tp.forum_id, tr.client_id
                FROM Topics AS tp
                INNER JOIN Torrents AS tr
                    ON tr.info_hash = tp.info_hash
            ) AS t
            GROUP BY t.forum_id
            HAVING COUNT(1) > 1
        ';

        $forums = $this->db->query($query, [], PDO::FETCH_COLUMN);

        return array_map('intval', $forums);
    }

    /**
     * Определяем лимит пиров для регулировки в зависимости от настроек для подраздела и торрент клиента.
     */
    public static function getPeerLimit(
        TopicControl $control,
        int          $clientControlPeers,
        int          $subsectionControlPeers
    ): int {
        $controlPeers = $control->peersLimit;

        // Задан лимит для клиента и для раздела.
        if ($clientControlPeers > -1 && $subsectionControlPeers > -1) {
            // Если лимит для клиента меньше лимита для подраздела, то используем лимит для клиента.
            $controlPeers = $subsectionControlPeers;
            if ($clientControlPeers < $subsectionControlPeers) {
                $controlPeers = $clientControlPeers;
            }
        } // Задан лимит только для клиента.
        elseif ($clientControlPeers > -1) {
            $controlPeers = $clientControlPeers;
        } // Задан лимит только для раздела.
        elseif ($subsectionControlPeers > -1) {
            $controlPeers = $subsectionControlPeers;
        }

        return max($controlPeers, 0);
    }

    /**
     * Определить желаемое состояние раздачи в клиенте, в зависимости от текущих значений и настроек.
     */
    public function determineDesiredState(TopicPeers $topic, int $peerLimit, bool $isSeeding): DesiredStatusChange
    {
        $controlOptions = $this->options;

        // Если у раздачи нет личей и выбрана опция "не сидировать без личей", то рандомно останавливаем раздачу.
        if ($isSeeding && self::shouldSkipSeeding(control: $controlOptions, topic: $topic)) {
            return DesiredStatusChange::RandomStop;
        }

        // Расчётное значение пиров раздачи.
        $peers = self::calculatePeers(control: $controlOptions, topic: $topic, isSeeding: $isSeeding);

        // Если текущее количество пиров равно лимиту - то ничего с раздачей не делаем.
        if ($peers === $peerLimit) {
            return DesiredStatusChange::Nothing;
        }

        // Если раздача раздаётся, и лимит не превышает - ничего не делаем.
        if ($isSeeding && $peers < $peerLimit) {
            return DesiredStatusChange::Nothing;
        }

        // Если раздача остановлена и лимит превышает - ничего не делаем.
        if (!$isSeeding && $peers > $peerLimit) {
            return DesiredStatusChange::Nothing;
        }

        // Если состояние раздачи нужно переключить, но разница с лимитом не велика, то применяем рандом.
        if (abs($peers - $peerLimit) <= $controlOptions->randomApplyCount) {
            return $isSeeding
                ? DesiredStatusChange::RandomStop
                : DesiredStatusChange::RandomStart;
        }

        // Если есть сиды и пиров больше нужного - останавливаем раздачу. В противном случае - запускам.
        return $topic->seeders > 0 && $peers > $peerLimit
            ? DesiredStatusChange::Stop
            : DesiredStatusChange::Start;
    }

    /**
     * Определяет, следует ли остановить сидирование раздачи.
     */
    private static function shouldSkipSeeding(TopicControl $control, TopicPeers $topic): bool
    {
        return !$control->seedingWithoutLeechers && $topic->leechers === 0 && $topic->seeders > 1;
    }

    /**
     * Вычисление количества пиров раздачи, в зависимости от выбранных настроек.
     */
    private static function calculatePeers(TopicControl $control, TopicPeers $topic, bool $isSeeding): int
    {
        // Расчётное значение пиров раздачи.
        $peers = $topic->seeders;

        // Если выбрана опция учёта личей как пиров, то плюсуем их.
        if ($control->countLeechersAsPeers) {
            $peers += $topic->leechers;
        }

        // Если выбрана опция игнорирования части сидов-хранителей на раздаче и они есть.
        if ($topic->keepers > 0 && $control->excludedKeepersCount > 0) {
            // Количество сидов хранителей на раздаче.
            $keepers = $topic->keepers;

            // Если раздача запущена, то вычитаем себя из сидов-хранителей.
            if ($isSeeding) {
                $keepers--;
            }

            // Вычитаем количество исключаемых хранителей.
            $peers -= min($keepers, $control->excludedKeepersCount);
        }

        return max(0, $peers);
    }
}
