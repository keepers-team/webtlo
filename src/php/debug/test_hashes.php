<?php

declare(strict_types=1);

use KeepersTeam\Webtlo\App;

require __DIR__ . '/../../vendor/autoload.php';

$app = App::create();

$log = $app->getLogger();

$log->debug('start');

$api = $app->getApiClient();


try {
    $testTimes = 1;

    $data = json_decode(file_get_contents('hashes.json'), true);

    $hashes = array_slice($data, -1 * 32 * 50);

    $start = microtime(true);

    for ($i = 0; $i < $testTimes; $i++) {
        $details = $api->getTopicsDetails($hashes, \KeepersTeam\Webtlo\External\Api\V1\TopicSearchMode::HASH);
    }

    $total = microtime(true) - $start;

    $averageSeconds = $total/$testTimes;
    var_dump($total, \KeepersTeam\Webtlo\Helper::convertSeconds((int)$total, true));
    var_dump($averageSeconds, \KeepersTeam\Webtlo\Helper::convertSeconds((int)$averageSeconds, true));


} catch (Exception $e) {
    $log->warning($e->getMessage());
}


$log->debug('end');
