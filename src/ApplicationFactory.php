<?php

namespace KeepersTeam\Webtlo;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteCollectorProxy;
use UMA\DIC\Container;
use Comet\Comet;

final class ApplicationFactory
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Logger factory
     *
     * @param string $storage Storage for file-baked loggers
     * @param string $name Logger name
     * @param bool $useFileLogging Whether to log event to files
     * @param string $logLevel Logging level
     * @return LoggerInterface
     */
    private function configureLogger(string $storage, string $name, bool $useFileLogging, string $logLevel): LoggerInterface
    {
        $daysRetention = 30;
        $dateFormat = "Y-m-d\TH:i:s";
        $output = "[%datetime%] %channel% %level_name%: %message% %context%\n";
        $formatter = new LineFormatter($output, $dateFormat, false, true);
        $logger = new Logger($name);

        if ($useFileLogging) {
            $logsDirectory = $storage . DIRECTORY_SEPARATOR . "logs";
            $logName = $logsDirectory . DIRECTORY_SEPARATOR . $name . '.log';

            if (!file_exists($storage) && !mkdir($storage, 0755, true)) {
                $this->logger->emergency(sprintf("Can't create logs storage at %s", $logsDirectory));
                exit(1);
            }
            $logger->pushHandler((new RotatingFileHandler($logName, $daysRetention, $logLevel))->setFormatter($formatter));
        }
        $logger->pushHandler((new StreamHandler('php://stdout', $logLevel))->setFormatter($formatter));
        return $logger;
    }

    /**
     * Configure database
     *
     * @param string $storage Storage for database
     * @return DB
     */
    private function configureDatabase(string $storage, LoggerInterface $logger): DB
    {
        $db = DB::create($logger, $storage);
        if ($db === false) {
            $this->logger->emergency("Unable to proceed with uninitialized database, exiting…");
            exit(1);
        }
        return $db;
    }

    /**
     * Configure application settings
     *
     * @param string $storage Storage for config
     * @return TIniFileEx Half-baked settings handler
     */
    private function configureSettings(string $storage): TIniFileEx
    {
        return new TIniFileEx($storage);
    }

    /**
     * Configure application routing table
     *
     * @noinspection PhpUndefinedMethodInspection
     * @param Comet $app Application
     */
    private function configureRoutes(Comet $app): void
    {
        $app->get('/', [Routes\LegacyRouter::class, 'home']);

        $app->group('/api/v0', function (RouteCollectorProxy $group) {
            $group->get('/check_new_version', [Routes\LegacyRouter::class, 'checkNewVersion'])->setName('checkNewVersion');
        });
        $app->serveStatic('static', ['css', 'js', 'ico', 'otf', 'woff', 'woff2', 'svg', 'eot']);
    }

    /**
     * Configure middleware for application table
     *
     * @noinspection PhpUndefinedMethodInspection
     * @param Comet $app Application
     */
    private function configureMiddleware(Comet $app, LoggerInterface $logger): void
    {
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(true, true, true, $logger);
    }


    /**
     * Create application with given params
     *
     * @param string $host Host (interface) to bind on
     * @param int $port Port to bind on
     * @param int $workers How many workers to spawn
     * @param string $logLevel Logging level
     * @param bool $useFileLogging Whether to log event to files
     * @param string $storage Storage directory for application
     * @return Comet Application
     */
    public function create(string $host, int $port, int $workers, bool $useFileLogging, string $logLevel, string $storage): Comet
    {
        $webtlo_version = Utils::getVersion();
        $appLogger = self::configureLogger($storage, 'application', $useFileLogging, $logLevel);
        $dbLogger = self::configureLogger($storage, 'database', $useFileLogging, $logLevel);
        $db = self::configureDatabase($storage, $dbLogger);
        $ini = self::configureSettings($storage);

        $container = new Container([
            'webtlo_version' => $webtlo_version,
            'db' => $db,
            'ini' => $ini,
            'logger' => $appLogger
        ]);

        $app = new Comet([
            'host' => $host,
            'port' => $port,
            'workers' => $workers,
            'logger' => $appLogger,
            'container' => $container,
        ]);

        $this->configureMiddleware($app, $appLogger);
        $this->configureRoutes($app);
        return $app;
    }
}
