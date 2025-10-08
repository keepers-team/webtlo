<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Storage\Traits;

use KeepersTeam\Webtlo\Backup;
use KeepersTeam\Webtlo\Helper;
use RuntimeException;

trait DbMigration
{
    /**
     * Проверить текущую версию БД и, при необходимости, выполнить инициализацию/миграцию.
     */
    protected function checkDatabaseVersion(string $databasePath): void
    {
        // Определим текущую версию БД.
        $currentVersion = (int) ($this->queryColumn('PRAGMA user_version') ?? 0);

        if ($currentVersion === self::DATABASE_VERSION) {
            // БД актуальна, делать ничего не нужно.
            return;
        }

        if ($currentVersion > self::DATABASE_VERSION) {
            // Странный случай, вероятно, откат версии ТЛО.
            throw new RuntimeException(
                sprintf(
                    'Ваша версия БД (#%d), опережает указанную в настройках web-TLO. '
                    . 'Вероятно, вы откатились на прошлую версию программы. '
                    . 'Удалите файл БД и перезапустите программу.',
                    $currentVersion
                )
            );
        }

        if ($currentVersion === 0) {
            // Создание БД с нуля
            $this->initTables();
        } elseif ($currentVersion > 0) {
            // Бекапим БД при изменении версии.
            Backup::database($databasePath, $currentVersion);

            // Выполняем миграцию.
            $this->migrateTables($currentVersion, self::DATABASE_VERSION);
        }
    }

    /**
     * Инициализация таблиц актуальной версии с нуля.
     */
    private function initTables(): void
    {
        $filePath = Helper::getMigrationPath(self::INIT_FILE);

        $query = file_get_contents($filePath);
        if (empty($query)) {
            $this->logger->warning('Ошибка загрузки файла инициализации', ['file' => $filePath]);

            throw new RuntimeException('Не удалось загрузить файл инициализации таблиц БД.');
        }

        $this->executeQuery($query);
    }

    /**
     * Совместимость со старыми версиями базы данных.
     *
     * @param int $version версия БД до применения миграций (текущая)
     */
    private function migrateTables(int $version, int $targetVersion): void
    {
        // Место хранения sql-файлов миграции/инициализации.
        $dir = Helper::getMigrationPath();

        $currentVersion = $version;

        // Все файлы должны соблюдать паттерн наименования: "0000-some-description.sql"
        // где 0000 - новая версия БД, после применения миграции.
        $files = $this->getFiles(sqlPath: $dir);
        foreach ($files as $file) {
            [$pragmaVersion] = explode('-', $file);

            if ($currentVersion < (int) $pragmaVersion) {
                $filePath = $dir . DIRECTORY_SEPARATOR . $file;

                $migration = file_get_contents($filePath);
                if (empty($migration)) {
                    $this->logger->warning('Ошибка загрузки файла инициализации', ['file' => $filePath]);

                    throw new RuntimeException(sprintf('Пустой файл миграции %s', $file));
                }

                ++$currentVersion;
                $this->executeQuery(sql: $migration);
            }
        }

        // Проверим, а все ли миграции выполнились.
        if ($currentVersion < $targetVersion) {
            $this->logger->error(
                'Миграция базы данных не завершена.',
                ['before' => $version, 'after' => $currentVersion, 'target' => $targetVersion]
            );
            $this->logger->debug('migration files', $files);

            throw new RuntimeException("Не удалось обновить БД до заданной версии ($targetVersion).");
        }

        $this->logger->info(
            'Миграция базы данных завершена успешно, user_version {before} => {after}.',
            ['before' => $version, 'after' => $currentVersion]
        );
    }

    /**
     * Получить список файлов миграции.
     *
     * @return string[]
     */
    private function getFiles(string $sqlPath): array
    {
        // Исключаем из скриптов миграции файл инициализации с нуля.
        $exclude = ['..', '.', self::INIT_FILE];

        // Use scandir and check if it's not false
        $files = scandir($sqlPath);
        if ($files === false) {
            // Return an empty array if scandir fails
            return [];
        }

        return array_values(array_diff($files, $exclude));
    }
}
