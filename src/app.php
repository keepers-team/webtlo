<?php

namespace KeepersTeam\Webtlo;

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use UMA\DIC\Container;
use Comet\Comet;

$webtlo_version = Utils::getVersion();
$storage_dir = Storage::getStorageDir();
$db = new DB($storage_dir);
$ini = new TIniFileEx($storage_dir);

function configureLogger(string $name): Logger
{
    $logsDirectory = Storage::getStorageDir() . DIRECTORY_SEPARATOR . "logs";
    $logName = $logsDirectory . DIRECTORY_SEPARATOR . 'application.log';
    Utils::mkdir_recursive($logsDirectory);

    $dateFormat = "Y-m-d\TH:i:s";
    $output = "[%datetime%] %channel% %level_name%: %message% %context%\n";
    $formatter = new LineFormatter($output, $dateFormat);

    $logger = new Logger($name);
    $logger->pushHandler((new RotatingFileHandler($logName, 30))->setFormatter($formatter));
    $logger->pushHandler((new StreamHandler('php://stdout'))->setFormatter($formatter));
    return $logger;
}

$logger = configureLogger('webtlo');

$container = new Container([
    'webtlo_version' => $webtlo_version,
    'db' => $db,
    'ini' => $ini,
    'logger' => $logger
]);

$app = new Comet([
    'host' => '127.0.0.1',
    'port' => 8080,
    'workers' => 4,
    'logger' => $logger,
    'container' => $container,
]);


$app->addRoutingMiddleware();

$app->addErrorMiddleware(true, true, true, $logger);

$app->get('/', [Routes\LegacyRouter::class, 'home']);

$app->serveStatic('static', ['css', 'js', 'ico', 'otf', 'woff', 'woff2', 'svg', 'eot']);

$app->run();
