<?php

declare(strict_types=1);

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode;
use KeepersTeam\Webtlo\Helper;

require __DIR__ . '/../../vendor/autoload.php';

$app = App::create();

$log = $app->getLogger();

$log->debug('start');

$api = $app->getApiForumClient();

try {
    $testTimes = 1;

    $data = json_decode((string) file_get_contents('hashes.json'), true);

    $hashes = array_slice($data, -1 * 32 * 50);

    $start = microtime(true);

    for ($i = 0; $i < $testTimes; ++$i) {
        $details = $api->getTopicsDetails($hashes, TopicSearchMode::HASH);
    }

    $total = microtime(true) - $start;

    $averageSeconds = $total / $testTimes;
    var_dump($total, Helper::convertSeconds((int) $total, true));
    var_dump($averageSeconds, Helper::convertSeconds((int) $averageSeconds, true));
} catch (Exception $e) {
    $log->warning($e->getMessage());
}

$log->debug('end');
