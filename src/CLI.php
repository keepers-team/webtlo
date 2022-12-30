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
    private static bool $DEBUG = false;
    private static string $LOGO = "
                   _     _____  _      ___  
     _ __ __  ___ | |__ |_   _|| |    / _ \ 
     \ V  V // -_)|  _ \  | |  | |__ | (_) |
      \_/\_/ \___||____/  |_|  |____| \___/ 
    ";

    private function wrapDefaults(string $value): string
    {
        if ($this->colors->isEnabled()) {
            return $this->colors->wrap($value, Colors::C_CYAN);
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

        $options->registerOption('filelog', 'Whether to log event to files.', 'f');
        $this->options->registerOption(
            'debug',
            'Run application in debug mode.',
            'd',
        );

        // For webserver
        $options->registerCommand('start', 'Start webTLO application');
        $this->options->registerOption(
            'host',
            'Host (interface) to bind on. Default is ' . $this->wrapDefaults(self::$HOST),
            'h',
            'address',
            'start'
        );
        $this->options->registerOption(
            'port',
            'Port to bind on. Default is ' . $this->wrapDefaults(self::$PORT),
            'p',
            'port',
            'start'
        );
        $this->options->registerOption(
            'workers',
            'How many workers to spawn. Default is ' . $this->wrapDefaults(self::$WORKERS),
            'w',
            'workers',
            'start'
        );

        // For migration
        $options->registerCommand('migrate', 'Perform database migration');
        $options->registerOption('backup', 'Whether to make backup before migration', 'b', false, 'migrate');
    }

    /**
     * Main program
     *
     * @param Options $options
     * @throws Exception
     */
    protected function main(Options $options): never
    {
        switch ($options->getCmd()) {
            case 'start':
                $factory = new ApplicationFactory($this);
                $this->success('Starting webTLO…');
                $app = $factory->create(
                    (string)$options->getOpt('host', self::$HOST),
                    (int)$options->getOpt('port', self::$PORT),
                    (int)$options->getOpt('workers', self::$WORKERS),
                    $options->getOpt('debug', self::$DEBUG),
                );
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
