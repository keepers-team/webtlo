<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\App;
use RuntimeException;
use Throwable;

final class ConsoleKernel
{
    /**
     * @param string[] $argv
     */
    public function handle(array $argv): int
    {
        if (empty($argv[1])) {
            echo "Usage: php bin/webtlo cron:command\n";

            return 1;
        }

        $command = CronCommand::tryFrom($argv[1]);
        if (!$command) {
            echo "Unknown command: $argv[1]\n";

            return 1;
        }

        $app    = App::createConsole(command: $command);
        $logger = $app->getLogger();

        // @TODO remove in 4.2
        if ($argv[0] !== 'bin/webtlo') {
            $notice = sprintf(
                '[DEPRECATED] Прямой запуск скрипта устарел. Используйте: %s',
                $command->cliUsage()
            );

            $logger->notice($notice);
            echo $notice . PHP_EOL;
        }

        try {
            $automation = $app->getAutomation();

            if (!$automation->isCommandEnabled(command: $command)) {
                $message = sprintf('[%s] Автоматическое выполнение отключено в настройках.', $command->name);

                $logger->notice($message);
                echo $message . PHP_EOL;

                exit(0);
            }

            $command->run(app: $app);

            return 0;
        } catch (RuntimeException $e) {
            $logger->warning($e->getMessage());
            echo $e->getMessage() . PHP_EOL;

            return 1;
        } catch (Throwable $e) {
            $logger->error($e->getMessage());
            echo $e->getMessage() . PHP_EOL;

            return 1;
        } finally {
            $logger->info('-- DONE --');
        }
    }
}
