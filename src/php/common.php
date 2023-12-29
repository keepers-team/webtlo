<?php

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\TIniFileEx;

include_once dirname(__FILE__) . '/../vendor/autoload.php';

include_once dirname(__FILE__) . '/classes/date.php';
include_once dirname(__FILE__) . '/classes/log.php';
include_once dirname(__FILE__) . '/classes/db.php';
include_once dirname(__FILE__) . '/classes/proxy.php';
include_once dirname(__FILE__) . '/classes/Timers.php';
include_once dirname(__FILE__) . '/migration/Backup.php';

// подключаемся к базе
Db::create();

// данные о сидах устарели
$avgSeedersPeriodOutdated = TIniFileEx::read('sections', 'avg_seeders_period_outdated', 7);
$outdatedTime = time() - (int)$avgSeedersPeriodOutdated * 86400;

// Удалим устаревшие метки обновления.
Db::query_database(
    "DELETE FROM UpdateTime WHERE ud < ?",
    [$outdatedTime]
);

// Удалим раздачи из подразделов, для которых нет в списке "обновлённых".
Db::query_database(
    "DELETE FROM Topics WHERE pt <> 2 AND ss NOT IN (SELECT id FROM UpdateTime WHERE id < 100000)"
);

// Удалим устаревшие раздачи высокого приоритета.
Db::query_database(
    "DELETE FROM Topics
        WHERE pt = 2
            AND IFNULL((SELECT ud FROM UpdateTime WHERE id = 9999), 0) < ?
            AND ss NOT IN (SELECT id FROM UpdateTime WHERE id < 100000)",
    [$outdatedTime]
);


function get_settings($filename = ''): array
{
    // TODO container auto-wire.
    $ini      = new TIniFileEx($filename);
    $settings = new Settings($ini);

    return $settings->populate();
}

function convert_bytes($size): string
{
    return Helper::convertBytes($size);
}

function convert_seconds($seconds, $leadZeros = false): string
{
    return Helper::convertSeconds($seconds, $leadZeros);
}

function rmdir_recursive($path): bool
{
    return Helper::removeDirRecursive($path);
}

function mkdir_recursive($path): bool
{
    return Helper::makeDirRecursive($path);
}