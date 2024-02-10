<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\DTO\UpdateDetailsResultObject;
use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Timers;
use Exception;
use Psr\Log\LoggerInterface;

/** Получение деталей о раздаче. */
final class TopicDetails
{
    private const TOPIC_KEYS = [
        'id', // topic_id
        'info_hash',
        'name', // topic_title
        'poster', // poster_id
        'seeder_last_seen',
    ];

    private ?UpdateDetailsResultObject $result = null;

    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly Topics          $topics,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws Exception
     */
    public function fillDetails(int $beforeUpdate, int $perRun = 5000): void
    {
        Timers::start('full');
        $tab = CloneTable::create('Topics', self::TOPIC_KEYS);

        $runs = (int)ceil($beforeUpdate / $perRun);

        $len    = strlen((string)$beforeUpdate);
        $runLog = "Обновление раздач [%{$len}d/%d]";

        $exec = [];
        $this->logger->debug(
            'Начинаем обновление сведений о раздачах',
            ['topics' => $beforeUpdate, 'perRun' => $perRun, 'runs' => $runs]
        );
        for ($run = 1; $run <= $runs; $run++) {
            Timers::start("chunk_$run");

            $topics = $this->topics->getUnnamedTopics($perRun);
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

            $this->logger->debug(sprintf($runLog, min($run * $perRun, $beforeUpdate), $beforeUpdate));
            $exec[] = Timers::getExec("chunk_$run");
        }

        $this->result = new UpdateDetailsResultObject(
            $beforeUpdate,
            $this->topics->countUnnamed(),
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
        $response = $this->apiClient->getTopicsDetails($topics);
        if ($response instanceof ApiError) {
            $this->logger->error(sprintf('%d %s', $response->code, $response->text));
            throw new Exception('Error: Не получены дополнительные данные о раздачах');
        }

        $details = [];

        foreach ($response->topics as $topic) {
            $details[$topic->id] = array_combine(self::TOPIC_KEYS, [
                $topic->id,
                $topic->hash,
                $topic->title,
                $topic->poster,
                (int)$topic->lastSeeded->format('U'),
            ]);
        }

        return $details;
    }
}
