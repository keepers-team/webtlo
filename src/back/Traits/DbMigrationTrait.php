<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Traits;

use KeepersTeam\Webtlo\Backup;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Log;
use RuntimeException;

trait DbMigrationTrait
{
    /**
     * Проверить текущую версию БД и, при необходимости, выполнить инициализацию/миграцию.
     */
    protected function checkDatabaseVersion(string $databasePath): void
    {
        // Определим текущую версию БД.
        $currentVersion = (int)($this->queryColumn('PRAGMA user_version') ?? 0);

        if ($currentVersion === self::DATABASE_VERSION) {
            // БД актуальна, делать ничего не нужно.
            return;
        } elseif ($currentVersion > self::DATABASE_VERSION) {
            // Странный случай, вероятно, откат версии ТЛО.
            throw new RuntimeException(
                sprintf(
                    'Ваша версия БД (#%d), опережает указанную в настройках web-TLO. Вероятно, вы откатились на прошлую версию программы. Удалите файл БД и запустите обновление сведений.',
                    $currentVersion
                )
            );
        } elseif ($currentVersion === 0) {
            // Создание БД с нуля
            $this->initTables();
        } elseif ($currentVersion > 0) {
            // Бекапим БД при изменении версии.
            Backup::database($databasePath, $currentVersion);

            // Выполняем миграцию.
            $this->migrateTables($currentVersion);
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
            throw new RuntimeException('Не удалось загрузить файл инициализации таблиц БД.');
        }

        $this->executeQuery($query);
    }

    /**
     * Совместимость со старыми версиями базы данных.
     *
     * @param int $version Текущая версия БД
     */
    private function migrateTables(int $version): void
    {
        // Место хранения sql-файлов миграции/инициализации.
        $dir = Helper::getMigrationPath();

        $currentVersion = $version;

        // Все файлы должны соблюдать паттерн наименования: "0000-some-description.sql"
        // где 0000 - новая версия БД, после применения миграции.
        foreach ($this->getFiles($dir) as $file) {
            [$pragmaVersion] = explode('-', $file);
            $pragmaVersion = (int)$pragmaVersion;

            if ($currentVersion < $pragmaVersion) {
                $query = file_get_contents($dir . DIRECTORY_SEPARATOR . $file);
                if (empty($query)) {
                    throw new RuntimeException(sprintf('Пустой файл миграции %s', $file));
                }

                $currentVersion++;
                $this->executeQuery($query);
            }
        }

        Log::append(sprintf('Бд обновлена, user_version %d => %d.', $version, $currentVersion));
    }

    /**
     * Получить список файлов миграции.
     *
     * @param string $sqlPath
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
