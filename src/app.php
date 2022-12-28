<?php
require_once __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\LegacyRouter;

$app = new Comet\Comet([
    'host' => '127.0.0.1',
    'port' => 8080,
    'workers' => 4,
]);

$app->get('/', [LegacyRouter::class, 'home']);

$app->run();
