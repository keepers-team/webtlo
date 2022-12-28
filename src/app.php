<?php
require_once __DIR__ . '/../vendor/autoload.php';

use KeepersTeam\Webtlo\LegacyRouter;

$app = new Comet\Comet([
    'host' => '127.0.0.1',
    'port' => 8080,
    'workers' => 4,
]);


$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->get('/', [LegacyRouter::class, 'home']);

$app->serveStatic('static', ['css', 'js', 'ico', 'otf', 'woff', 'woff2', 'svg', 'eot']);

$app->run();
