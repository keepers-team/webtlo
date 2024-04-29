<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Update\ForumTree;
use KeepersTeam\Webtlo\Update\HighPriority;
use KeepersTeam\Webtlo\Update\Subsections;

/**
 * Выполнение обновления сведений из разных источников.
 * Либо полное обновление всего, либо конкретный модуль.
 */

$update_result = [
    'result' => '',
];

// Создаём контейнер и пишем в лог.
$app = AppContainer::create('update.log');
$log = $app->getLogger();
try {
    // Список задач, которых можно запустить.
    $pairs = [
        // списки раздач в хранимых подразделах
        'subsections' => 'runClass',
        // список высокоприоритетных раздач
        'priority'    => 'runClass',
        // раздачи других хранителей
        'keepers'     => 'keepers',
        // раздачи в торрент-клиентах
        'clients'     => 'tor_clients',
    ];

    // Процессы, которым нужно обновление дерева подразделов.
    $topicsRelated = ['subsections', 'priority'];

    $process = $_GET['process'] ?: null;
    if (null !== $process && 'all' !== $process) {
        $pairs = array_filter($pairs, fn($el) => $el === $process, ARRAY_FILTER_USE_KEY);
    }

    $updateForumTree = false;
    if (count($pairs) > 1) {
        $updateForumTree = true;
    } elseif (count($pairs) === 1) {
        $runProcess = array_key_first($pairs);
        if (in_array($runProcess, $topicsRelated)) {
            $updateForumTree = true;
        }
    }

    if ($updateForumTree) {
        /** @var ForumTree $forumTree */
        $forumTree = $app->get(ForumTree::class);
        $forumTree->update();
    }

    $config = $app->getLegacyConfig();
    // Запускаем задачи по очереди.
    foreach ($pairs as $process => $fileName) {
        // Новые классы вызываем через контейнер.
        if ($process === 'subsections') {
            /**
             * Обновляем раздачи в хранимых подразделах.
             *
             * @var Subsections $updateSubsections
             */
            $updateSubsections = $app->get(Subsections::class);
            $updateSubsections->update(config: $config, schedule: true);
        } elseif ($process === 'priority') {
            /**
             * Обновляем список высокоприоритетных раздач.
             *
             * @var HighPriority $highPriority
             */
            $highPriority = $app->get(HighPriority::class);
            $highPriority->update(config: $config);
        } else {
            include_once sprintf('%s/../common/%s.php', dirname(__FILE__), $fileName);
        }
    }
    $log->info('-- DONE --');
} catch (Throwable $e) {
    $update_result['result'] = 'В процессе обновления сведений были ошибки. '
        . 'Для получения подробностей обратитесь к журналу событий.';
    $log->error($e->getMessage());
}

// Выводим лог
$update_result['log'] = Log::get();

echo json_encode($update_result, JSON_UNESCAPED_UNICODE);
