<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Update\ForumTree;
use KeepersTeam\Webtlo\Update\KeepersReports;
use KeepersTeam\Webtlo\Update\Subsections;
use KeepersTeam\Webtlo\Update\TorrentsClients;

/**
 * Выполнение обновления сведений из разных источников.
 * Либо полное обновление всего, либо конкретный модуль.
 */
$update_result = [
    'result' => '',
];

// Создаём контейнер и пишем в лог.
$app = App::create('update.log');
$log = $app->getLogger();

try {
    // Список задач, которых можно запустить.
    $pairs = [
        'subsections' => Subsections::class,
        'keepers'     => KeepersReports::class,
        'clients'     => TorrentsClients::class,
    ];

    // Процессы, которым нужно обновление дерева подразделов.
    $topicsRelated = ['subsections'];

    // Получение запрашиваемого процесса.
    $process = $_GET['process'] ?? null;

    if ($process !== null && $process !== 'all') {
        $pairs = array_filter(
            $pairs,
            static fn($key) => $key === $process,
            ARRAY_FILTER_USE_KEY
        );
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

    // Запускаем задачи по очереди.
    foreach ($pairs as $process => $className) {
        /** @var null|object $instance */
        $instance = $app->get($className);

        if ($instance && method_exists($instance, 'update')) {
            $instance->update();
        } else {
            $log->notice('Неизвестный тип обновления данных', ['process' => $process]);
        }
    }
} catch (Throwable $e) {
    $update_result['result'] = 'В процессе обновления сведений были ошибки. '
        . 'Для получения подробностей обратитесь к журналу событий.';
    $log->error($e->getMessage());
} finally {
    $log->info('-- DONE --');
}

// Выводим лог
$update_result['log'] = $app->getLoggerRecords();

echo json_encode($update_result, JSON_UNESCAPED_UNICODE);
