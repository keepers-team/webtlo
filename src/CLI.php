<?php

namespace KeepersTeam\Webtlo;

use Exception;
use splitbrain\phpcli\PSR3CLIv3;
use splitbrain\phpcli\Colors;
use splitbrain\phpcli\Options;

class CLI extends PSR3CLIv3
{
    private static string $HOST = '0.0.0.0';
    private static int $PORT = 8080;
    private static int $WORKERS = 4;
    private static string $DIR = 'data';
    private static string $LOGO = "
                   _     _____  _      ___  
     _ __ __  ___ | |__ |_   _|| |    / _ \ 
     \ V  V // -_)|  _ \  | |  | |__ | (_) |
      \_/\_/ \___||____/  |_|  |____| \___/ 
    ";

    private function wrapDefaults(string $value, string $color): string
    {
        if ($this->colors->isEnabled()) {
            return $this->colors->wrap($value, $color);
        } else {
            return $value;
        }
    }

    /**
     * Register options and arguments on the given $options object
     *
     * @param Options $options
     * @return void
     */
    protected function setup(Options $options): void
    {
        $options->setHelp('Simple application for torrent management');
        $options->setCommandHelp("Use one of this commands");
        $options->useCompactHelp();

        $options->registerOption(
            'no-filelog',
            'Do not log events to files. Useful to run inside containers and/or with external logs aggregation.',
            'n'
        );
        $options->registerOption(
            'storage',
            "Storage directory for webTLO. Default is {$this->wrapDefaults(self::$DIR, Colors::C_CYAN)} (relative to {$this->wrapDefaults($this->options->getBin(), Colors::C_BROWN)})",
            's',
            'storage',
        );

        // For webserver
        $options->registerCommand('start', 'Start webTLO application');
        $options->registerOption(
            'host',
            "Host (interface) to bind on. Default is {$this->wrapDefaults(self::$HOST, Colors::C_CYAN)}",
            'h',
            'address',
            'start'
        );
        $options->registerOption(
            'port',
            "Port to bind on. Default is {$this->wrapDefaults(self::$PORT, Colors::C_CYAN)}",
            'p',
            'port',
            'start'
        );
        $options->registerOption(
            'workers',
            "How many workers to spawn. Default is {$this->wrapDefaults(self::$WORKERS, Colors::C_CYAN)}",
            'w',
            'workers',
            'start'
        );

        // For migration
        $options->registerCommand('migrate', 'Perform database migration');
        $options->registerOption('backup', 'Whether to make backup before migration', 'b', false, 'migrate');
    }

    /***
     * @return string Storage directory for webTLO
     */
    private function getStorage(Options $options): string
    {
        $directory = $options->getOpt('storage', self::$DIR);
        $storage = Utils::normalizePath($directory);
        if (!file_exists($storage) && !mkdir($storage, 0755, true)) {
            $this->emergency(sprintf("Can't create application storage at %s", $storage));
            exit(1);
        }

        if (file_exists($storage) && (!is_writable($storage) || !is_readable($storage))) {
            $this->emergency(sprintf("Storage directory at %s isn't writable and/or readable, exiting…", $storage));
            exit(1);
        }

        return $storage;
    }

    private function getHost(Options $options): string
    {
        $host = $options->getOpt('host', self::$HOST);
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $this->emergency(sprintf("%s doesn't looks like valid IP, exiting…", $host));
            exit(1);
        }
        return $host;
    }

    private function getPort(Options $options): int
    {
        $rawPort = $options->getOpt('port', self::$PORT);
        if (!filter_var($rawPort, FILTER_SANITIZE_NUMBER_INT)) {
            $this->emergency(sprintf("%s doesn't looks like a port number, exiting…", $rawPort));
            exit(1);
        }
        $port = (int)$rawPort;
        $minPort = 0;
        $maxPort = 2 << 15;
        if ($minPort >= $port || $port >= $maxPort) {
            $this->emergency(sprintf("Got port %d, but it should be between %d and %d, exiting…", $port, $minPort, $maxPort));
            exit(1);
        }
        return $port;
    }

    private function getWorkers(Options $options): int
    {
        $rawWorkers = $options->getOpt('workers', self::$WORKERS);
        if (!filter_var($rawWorkers, FILTER_SANITIZE_NUMBER_INT)) {
            $this->emergency(sprintf("%s doesn't looks like a correct workers count, exiting…", $rawWorkers));
            exit(1);
        }
        $workers = (int)$rawWorkers;
        $minWorkers = 0;
        $maxWorkers = 2 << 4;
        if ($minWorkers >= $workers || $workers > $maxWorkers) {
            $this->emergency(sprintf("It's unreasonable to set %d workers — it should be between %d and %d; exiting…", $workers, $minWorkers, $maxWorkers));
            exit(1);
        }
        return $workers;
    }

    /**
     * Main program
     *
     * @param Options $options
     * @throws Exception
     */
    protected function main(Options $options): never
    {
        $storage = $this->getStorage($options);
        $logLevel = $this->options->getOpt('loglevel', $this->logdefault);

        switch ($options->getCmd()) {
            case 'start':
                $host = $this->getHost($options);
                $port = $this->getPort($options);
                $workers = $this->getWorkers($options);
                $useFileLogging = !$options->getOpt('no-filelog');

                $app = (new ApplicationFactory($this))->create($host, $port, $workers, $useFileLogging, $logLevel, $storage);
                $this->success('Starting webTLO…');
                $app->run();
                exit;
            case 'migrate':
                $this->success('The migrate command was called');
                exit;
            default:
                echo $this->colors->wrap(self::$LOGO . PHP_EOL, Colors::C_GREEN);
                echo $options->help();
                exit;
        }
    }
}
