<?php

namespace KeepersTeam\Webtlo;

require_once __DIR__ . '/../vendor/autoload.php';

use UMA\DIC\Container;
use Comet\Comet;

$webtlo_version = Utils::getVersion();
$storage_dir = Storage::getStorageDir();
$db = new DB($storage_dir);
$ini = new TIniFileEx($storage_dir);

$container = new Container([
    'webtlo_version' => $webtlo_version,
    'db' => $db,
    'ini' => $ini,
]);

$app = new Comet([
    'host' => '127.0.0.1',
    'port' => 8080,
    'workers' => 4,
    'container' => $container,
]);


$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->get('/', [LegacyRouter::class, 'home']);

$app->serveStatic('static', ['css', 'js', 'ico', 'otf', 'woff', 'woff2', 'svg', 'eot']);

$app->run();
