<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use Db;
use Api;
use PDO;
use Timers;
use Exception;
use KeepersTeam\Webtlo\DTO\UpdateDetailsResultObject;

/** Получение деталей о раздаче. */
final class TopicDetails
{
    private const TOPIC_KEYS = [
        'id', // topic_id
        'hs', // info_hash
        'na', // topic_title
        'ps', // poster_id
        'ls', // seeder_last_seen
    ];

    private ?UpdateDetailsResultObject $result = null;

    public function __construct(private readonly Api $api)
    {
    }

    /**
     * @throws Exception
     */
    public function fillDetails(int $beforeUpdate, int $perRun = 5000): void
    {
        Timers::start('full');
        $tab = CloneTable::create('Topics', self::TOPIC_KEYS);

        $runs = (int)ceil($beforeUpdate / $perRun);

        $exec = [];
        for ($run = 1; $run <= $runs; $run++) {
            Timers::start("chunk_$run");

            $topics = $this->getUnnamedTopics($perRun);
            if (count($topics)) {
                $details = $this->getDetails($topics);
                if (count($details)) {
                    $tab->cloneFillChunk($details);
                }
                if ($tab->cloneCount()) {
                    $tab->moveToOrigin();
                }
                $tab->clearClone();
            }

            $exec[] = Timers::getExec("chunk_$run");
        }

        $this->result = new UpdateDetailsResultObject(
            $beforeUpdate,
            self::countUnnamed(),
            $perRun,
            $runs,
            Timers::getExec('full'),
            array_sum($exec) / count($exec),
        );
    }

    public function getResult(): ?UpdateDetailsResultObject
    {
        return $this->result ?? null;
    }

    /**
     * Запросить детали о списке раздач.
     *
     * @throws Exception
     */
    public function getDetails(array $topics): array
    {
        $topicsDetails = $this->api->getTorrentTopicData($topics);
        if (empty($topicsDetails)) {
            throw new Exception("Error: Не получены дополнительные данные о раздачах");
        }
        $details = [];
        foreach ($topicsDetails as $topicId => $topicDetails) {
            if (empty($topicDetails)) {
                continue;
            }
            $details[$topicId] = array_combine(self::TOPIC_KEYS, [
                $topicId,
                $topicDetails['info_hash'],
                $topicDetails['topic_title'],
                $topicDetails['poster_id'],
                $topicDetails['seeder_last_seen'],
            ]);
        }

        return $details;
    }

    /** Количество раздач без названия. */
    public static function countUnnamed(): int
    {
        return Db::query_count("SELECT COUNT(1) FROM Topics WHERE na IS NULL OR na = ''");
    }

    /** Количество раздач без названия. */
    public static function getUnnamedTopics(int $limit = 5000): array
    {
        return Db::query_database(
            "select id from Topics WHERE na IS NULL or na = '' LIMIT ?",
            [$limit],
            true,
            PDO::FETCH_COLUMN
        );
    }
}