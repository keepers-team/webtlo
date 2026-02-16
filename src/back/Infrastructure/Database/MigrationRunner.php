<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Database;

use KeepersTeam\Webtlo\Backup;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MigrationRunner
{
    /**
     * Актуальная версия БД.
     */
    public const DATABASE_VERSION = 15;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int             $targetVersion,
        private readonly string          $databasePath,
        private readonly string          $filesPath,
    ) {}

    /**
     * Проверить текущую версию БД и, при необходимости, выполнить инициализацию/миграцию.
     */
    public function migrate(ConnectionInterface $con): void
    {
        // Определим текущую версию БД.
        $current = (int) ($con->queryColumn('PRAGMA user_version') ?? 0);

        // БД актуальна, делать ничего не нужно.
        if ($current === $this->targetVersion) {
            return;
        }

        // Создание БД с нуля
        if ($current === 0) {
            $this->initSchema($con);

            return;
        }

        // Странный случай, вероятно, откат версии ТЛО.
        if ($current > $this->targetVersion) {
            throw new RuntimeException(
                sprintf(
                    'Ваша версия БД (#%d), опережает указанную в настройках web-TLO. '
                    . 'Вероятно, вы откатились на прошлую версию программы. '
                    . 'Удалите файл БД и перезапустите программу.',
                    $current
                )
            );
        }

        $this->runIncrementalMigrations($con, $current);
    }

    /**
     * Инициализация таблиц актуальной версии с нуля.
     */
    private function initSchema(ConnectionInterface $con): void
    {
        $file = $this->filesPath . '/schema/init.sql';

        $sql = file_get_contents($file);
        if (!$sql) {
            $this->logger->warning('Ошибка загрузки файла инициализации', ['file' => $file]);

            throw new RuntimeException('Не удалось загрузить файл инициализации таблиц БД.');
        }

        $con->executeQuery($sql);
    }

    /**
     * Совместимость со старыми версиями базы данных.
     *
     * Все файлы должны соблюдать паттерн наименования: "0000-some-description.sql"
     * где 0000 - новая версия БД, после применения миграции.
     *
     * @param int $startVersion версия БД до применения миграций (текущая)
     */
    private function runIncrementalMigrations(ConnectionInterface $con, int $startVersion): void
    {
        // Делаем бекап БД при изменении версии.
        Backup::database($this->databasePath, $startVersion);

        $currentVersion = $startVersion;

        $migrationPath = $this->filesPath . '/migrations';

        $files = scandir($migrationPath);
        if ($files === false) {
            $this->logger->warning('Ошибка поиска списка миграций', ['path' => $migrationPath]);

            throw new RuntimeException('Ошибка поиска списка миграций.');
        }

        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            if (!preg_match('/^(\d+)-.*\.sql$/', $file, $m)) {
                continue;
            }

            $version = (int) $m[1];

            if ($version <= $currentVersion) {
                continue;
            }

            $filePath = $migrationPath . '/' . $file;

            $migration = file_get_contents($filePath);
            if (!$migration) {
                $this->logger->warning('Ошибка загрузки файла инициализации', ['file' => $filePath]);

                throw new RuntimeException(sprintf('Пустой файл миграции %s', $file));
            }

            $con->executeQuery($migration);

            $currentVersion = $version;
        }

        // Проверим, а все ли миграции выполнились.
        if ($currentVersion < $this->targetVersion) {
            $this->logger->error('Миграция базы данных не завершена.', [
                'before' => $startVersion,
                'after'  => $currentVersion,
                'target' => $this->targetVersion,
            ]);
            $this->logger->debug('migration files', $files);

            throw new RuntimeException("Не удалось обновить БД до заданной версии ($this->targetVersion).");
        }

        $this->logger->info(
            'Миграция базы данных завершена успешно, user_version {before} => {after}.',
            ['before' => $startVersion, 'after' => $currentVersion]
        );
    }
}
