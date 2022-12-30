<?php

namespace KeepersTeam\Webtlo;

require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use UMA\DIC\Container;
use Comet\Comet;

/**
 * @return string Storage directory for application
 */
function configureStorage(): string
{
    $directory = getenv('WEBTLO_DIR');
    if ($directory === false) {
        $directory = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . 'data';
    }
    $storage = Utils::normalizePath($directory);
    if (!file_exists($storage) && !mkdir($storage, 0755, true)) {
        $error = "Can't create %s for storage";
        die(sprintf($error, $storage));
    }

    if (file_exists($storage) && (!is_writable($storage) || !is_readable($storage))) {
        $error = "Directory %s isn't writable and/or readable, exiting…";
        die(sprintf($error, $storage));
    }

    return $storage;
}

/**
 * Logger factory
 *
 * @param string $storage Storage for file-baked loggers
 * @param string $name Logger name
 * @return Logger
 */
function configureLogger(string $storage, string $name): Logger
{
    $logsDirectory = $storage . DIRECTORY_SEPARATOR . "logs";
    $logName = $logsDirectory . DIRECTORY_SEPARATOR . $name . '.log';

    if (!file_exists($storage) && !mkdir($storage, 0755, true)) {
        $error = "Can't create %s for logs";
        die(sprintf($error, $logsDirectory));
    }

    $dateFormat = "Y-m-d\TH:i:s";
    $output = "[%datetime%] %channel% %level_name%: %message% %context%\n";
    $formatter = new LineFormatter($output, $dateFormat);

    $logger = new Logger($name);
    $logger->pushHandler((new RotatingFileHandler($logName, 30))->setFormatter($formatter));
    $logger->pushHandler((new StreamHandler('php://stdout'))->setFormatter($formatter));
    return $logger;
}

/**
 * Configure database
 *
 * @param string $storage Storage for database
 * @return DB
 */
function configureDatabase(string $storage): DB
{
    $logger = configureLogger($storage, 'database');
    $db = DB::create($logger, $storage);
    if ($db === false) {
        die('Unable to proceed with uninitialized database, exiting…');
    }
    return $db;
}

/**
 * Configure application settings
 *
 * @param string $storage Storage for config
 * @return TIniFileEx Half-baked settings handler
 */
function configureSettings(string $storage): TIniFileEx
{
    return new TIniFileEx($storage);
}

$webtlo_version = Utils::getVersion();
$storage = configureStorage();
$logger = configureLogger($storage, 'application');
$db = configureDatabase($storage);
$ini = configureSettings($storage);

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
