<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action;

use Generator;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Clients\Data\Torrents;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\External\Api\V1\TopicPeers;
use KeepersTeam\Webtlo\Timers;
use PDO;
use Psr\Log\LoggerInterface;

final class Control
{
    public function __construct(
        private readonly DB              $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getStoredHashes(int $torrentClientID, KeysObject $forums, Torrents $torrents): Generator
    {
        Timers::start("get_topics_$torrentClientID");

        $unaddedHashes = $torrents->getHashes();

        $hashesChunks = array_chunk($unaddedHashes, 999);

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
                $unaddedHashes = array_diff($unaddedHashes, $hashes);

                $topicsHashes[$forumId][] = $hashes;
            }
        }

        $topicsHashes = array_map(fn($el) => array_merge(...$el), $topicsHashes);

        $this->logger->info(
            'Поиск раздач в БД завершён за {sec}. Найдено раздач из хранимых подразделов {count} шт, из прочих {unadded} шт.',
            [
                'count'   => count($topicsHashes, COUNT_RECURSIVE) - count($topicsHashes),
                'unadded' => count($unaddedHashes),
                'sec'     => Timers::getExecTime("get_topics_$torrentClientID"),
            ]
        );

        // Сортируем подразделы по ИД.
        ksort($topicsHashes);
        if (count($unaddedHashes)) {
            $topicsHashes['unadded'] = $unaddedHashes;
        }
        unset($unaddedHashes);

        foreach ($topicsHashes as $group => $hashes) {
            yield $group => $hashes;
        }
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
     * Нужно ли остановить раздачу в торрент клиенте?
     */
    public static function shouldStopSeeding(
        TopicControl $control,
        int          $peerLimit,
        Torrent      $torrent,
        TopicPeers   $topic
    ): bool {
        // Количество сидов на раздаче.
        $seeders = $topic->seeders;

        // Если раздача запущена и есть сиды, то вычитаем себя из их числа.
        if ($seeders > 0 && !$torrent->paused) {
            $seeders--;
        }

        // Расчётное значение ПИРОВ раздачи. Пиры - всё другие участники, кроме меня.
        $peers = $seeders;

        // Если выбрана опция учёта личей как пиров, то плюсуем их.
        if ($control->countLeechersAsPeers) {
            $peers += $topic->leechers;
        }

        // Если выбрана опция игнорирования части сидов-хранителей на раздаче и они есть.
        if (null !== $topic->keepers && $control->excludedKeepersCount > 0) {
            // Количество сидов хранителей на раздаче.
            $keepers = count($topic->keepers);

            // Если раздача запущена, то вычитаем себя из сидов-хранителей.
            if ($keepers > 0 && !$torrent->paused) {
                $keepers--;
            }

            // Вычитаем количество исключаемых хранителей.
            $peers -= (int)min($keepers, $control->excludedKeepersCount);
        }

        // Если нет личей и настройка выключена, то такую раздачу держать запущенной не нужно.
        $skipSeedingWithoutLeechers = !$control->seedingWithoutLeechers && $topic->leechers === 0;

        // Сверяем количество пиров раздачи с лимитом.
        $toMuchPeers = max(0, $peers) >= $peerLimit;

        // Останавливаем раздачу, только если есть другие сиды и одно из:
        // - количество пиров больше заданного ограничения
        // - у раздачи нет личей и соответствующая опция выключена
        return $seeders > 0 && ($toMuchPeers || $skipSeedingWithoutLeechers);
    }
}
