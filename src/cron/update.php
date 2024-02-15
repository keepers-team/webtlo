<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\ForumTree;
use KeepersTeam\Webtlo\Update\HighPriority;
use KeepersTeam\Webtlo\Update\Subsections;
use KeepersTeam\Webtlo\Update\TopicsDetails;

try {
    // Инициализируем контейнер, без имени лога, чтобы записи не двоились от legacy/di.
    $app = AppContainer::create();

    $config = $app->getLegacyConfig();

    Timers::start('full_update');

    /**
     * Обновляем дерево подразделов.
     *
     * @var ForumTree $forumTree
     */
    $forumTree = $app->get(ForumTree::class);
    $forumTree->update();

    /**
     * Обновляем раздачи в хранимых подразделах.
     *
     * @var Subsections $updateSubsections
     */
    $updateSubsections = $app->get(Subsections::class);
    $updateSubsections->update(config: $config, schedule: true);

    /**
     * Обновляем список высокоприоритетных раздач.
     *
     * @var HighPriority $highPriority
     */
    $highPriority = $app->get(HighPriority::class);
    $highPriority->update(config: $config);

    // обновляем дополнительные сведения о раздачах (названия раздач)
    /** @var TopicsDetails $detailsClass */
    $detailsClass = $app->get(TopicsDetails::class);
    $detailsClass->update();

    // обновляем списки раздач в торрент-клиентах
    include_once dirname(__FILE__) . '/../php/common/tor_clients.php';

    Log::append(sprintf('Обновление всех данных завершено за %s', Timers::getExecTime('full_update')));
} catch (Exception $e) {
    Log::append($e->getMessage());
}

// записываем в лог
Log::write('update.log');
