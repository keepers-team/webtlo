<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

use KeepersTeam\Webtlo\External\Api\V1\ApiError;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Tables\Topics;
use KeepersTeam\Webtlo\Timers;
use Psr\Log\LoggerInterface;
use RuntimeException;

/** Получение деталей о раздаче. */
final class TopicsDetails
{
    private const TOPIC_KEYS = [
        'id', // topic_id
        'info_hash',
        'name', // topic_title
        'poster', // poster_id
        'seeder_last_seen',
    ];

    public function __construct(
        private readonly ApiClient       $apiClient,
        private readonly Topics          $topics,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Выполнить обновление дополнительных сведений о раздачах. */
    public function update(int $topicsPerRun = 5000): void
    {
        // Проверяем наличие раздач, данные которых нужно загрузить.
        $countUnnamed = $this->topics->countUnnamed();
        if (!$countUnnamed) {
            $this->logger->notice('Обновление дополнительных сведений о раздачах не требуется.');

            return;
        }

        Timers::start('detailsUpdate');
        $this->fillDetails($countUnnamed, $topicsPerRun);
    }

    private function fillDetails(int $beforeUpdate, int $perRun): void
    {
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

        $this->logResult($beforeUpdate, array_sum($exec) / count($exec));
    }

    /**
     * Запросить дополнительные сведения по списку ид раздач.
     *
     * @param int[] $topics
     * @return array<string, mixed>[]
     */
    private function getDetails(array $topics): array
    {
        $response = $this->apiClient->getTopicsDetails($topics);
        if ($response instanceof ApiError) {
            throw new RuntimeException(sprintf('Ошибка получения данных (%s).', $response->text), $response->code);
        }

        $details = [];
        foreach ($response->topics as $topic) {
            $details[] = array_combine(self::TOPIC_KEYS, [
                $topic->id,
                $topic->hash,
                $topic->title,
                $topic->poster,
                $topic->lastSeeded->getTimestamp(),
            ]);
        }

        return $details;
    }

    private function logResult(int $before, int|float $averageExec): void
    {
        $this->logger->info(
            sprintf(
                'Обновление дополнительных сведений о раздачах завершено за %s.',
                Timers::getExecTime('detailsUpdate')
            )
        );

        $after = $this->topics->countUnnamed();
        $this->logger->debug(
            sprintf(
                'Раздач обновлено %d из %d. Среднее время выполнения %0.2fs.',
                $before - $after,
                $before,
                $averageExec
            )
        );
    }
}
