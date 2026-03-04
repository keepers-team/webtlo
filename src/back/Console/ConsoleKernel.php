<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
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
        if (basename($argv[0]) !== 'webtlo') {
            $notice = sprintf(
                '[DEPRECATED] Прямой запуск скрипта устарел. Используйте: "%s" вместо "%s"',
                $command->cliUsage(),
                $argv[0]
            );

            $logger->notice($notice);
            echo $notice . PHP_EOL;
        }

        $automation = $app->getAutomation();

        if (!$automation->isCommandEnabled(command: $command)) {
            $message = sprintf('[%s] Автоматическое выполнение отключено в настройках.', $command->name);

            $logger->notice($message);
            echo $message . PHP_EOL;

            return 0;
        }

        $lockPath = Helper::getStorageDir() . '/locks';
        $factory  = new LockFactory(store: new FlockStore(lockPath: $lockPath));

        $lock = $factory->createLock('webtlo-global', 1800);

        try {
            if (!$lock->acquire()) {
                $message = sprintf('[%s]. Запуск невозможен. Запущен другой процесс.', $command->name);
                $logger->warning($message, ['lockPath' => $lockPath]);
                echo $message . PHP_EOL;

                return 1;
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
            $lock->release();
            $logger->info('-- DONE --');
        }
    }
}
