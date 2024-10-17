<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Update\KeepersReports;

/**
 * Запуск обновления списка хранителей строго из планировщика.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[update.php, keepers.php]".
 */

try {
    // Инициализируем контейнер.
    $app = AppContainer::create('keepers.log');
    $log = $app->getLogger();

    $config = $app->getLegacyConfig();

    // Проверяем возможность запуска обновления.
    if (!Helper::isScheduleActionEnabled(config: $config, action: 'update')) {
        $log->notice(
            '[KeepersLists]. Автоматическое обновление списков раздач других хранителей отключено в настройках.'
        );

        return;
    }

    /** @var KeepersReports $keepersReports */
    $keepersReports = $app->get(KeepersReports::class);
    $keepersReports->updateReports(config: $config);
} catch (RuntimeException $e) {
    if (isset($log)) {
        $log->warning($e->getMessage());
    }
} catch (Throwable $e) {
    if (isset($log)) {
        $log->error($e->getMessage());
    }
} finally {
    if (isset($log)) {
        $log->info('-- DONE --');
    }
}
