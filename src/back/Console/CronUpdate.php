<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\ForumTree;
use KeepersTeam\Webtlo\Update\Subsections;
use KeepersTeam\Webtlo\Update\TopicsDetails;
use KeepersTeam\Webtlo\Update\TorrentsClients;
use Psr\Log\LoggerInterface;

/**
 * Запуск обновления списка хранителей строго из планировщика.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[update.php, keepers.php]".
 */
final class CronUpdate
{
    public function __construct(
        private readonly ForumTree       $forumTree,
        private readonly Subsections     $updateSubsections,
        private readonly TopicsDetails   $topicsDetails,
        private readonly TorrentsClients $torrentsClients,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        Timers::start('full_update');

        /**
         * Обновляем дерево подразделов.
         */
        $this->forumTree->update();

        /**
         * Обновляем раздачи в хранимых подразделах.
         */
        $this->updateSubsections->update();

        /**
         * Обновляем дополнительные сведения о раздачах (названия раздач).
         */
        $this->topicsDetails->update();

        /**
         * Обновляем списки раздач в торрент-клиентах.
         */
        $this->torrentsClients->update();

        $this->logger->info('Обновление всех данных завершено за {sec}', ['sec' => Timers::getExecTime('full_update')]);
    }
}
