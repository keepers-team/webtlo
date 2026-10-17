<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

/**
 * Бекапим конфиг.
 */
final class Backup
{
    /** Максимальное кол-во бекапов каждого вида. */
    private const MAX_BACKUPS = 5;
    /** Название подкаталога с бекапами. */
    private const FOLDER = 'backup';

    public static function config(string $path, int $version): void
    {
        $backupName = sprintf('config-v%d-%s.ini', $version, date('Y-m-d-H-i'));
        $backupPath = self::getPath();
        $backupFile = $backupPath . DIRECTORY_SEPARATOR . $backupName;

        // Бекапим конфиг.
        copy($path, $backupFile);

        // Удаляем лишние бекапы.
        self::clearBackups($backupPath, 'config-*.ini');
    }

    /**
     * @param string           $path    путь к файлу БД для создания бекапа
     * @param non-negative-int $version текущая версия БД
     * @param bool             $rotate  ротировать ли старые бекапы
     */
    public static function database(string $path, int $version, bool $rotate = true): void
    {
        $backupName = sprintf('webtlo-v%d-%s.db', $version, date('Y-m-d-H-i'));
        $backupPath = self::getPath();
        $backupFile = $backupPath . DIRECTORY_SEPARATOR . $backupName;

        // Бекапим БД.
        $done = copy($path, $backupFile);

        // Удаляем лишние бекапы в случае успешного создания нового бекапа.
        if ($done && $rotate) {
            self::clearBackups($backupPath, 'webtlo-*.db');
        }
    }

    /**
     * Найти последний бекап БД для указанной версии.
     *
     * @param positive-int $version
     *
     * @return ?string путь к файлу бекапа или null, если не найден
     */
    public static function findDatabaseBackup(int $version): ?string
    {
        $backupPath = self::getPath();

        $pattern = sprintf('webtlo-v%d-*.db', $version);
        $files   = glob($backupPath . DIRECTORY_SEPARATOR . $pattern);

        if (empty($files)) {
            return null;
        }

        // Сортируем по времени изменения (сначала новые).
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * Удаляем лишние бекапы.
     */
    private static function clearBackups(string $path, string $pattern): void
    {
        // Все файлы по указанному пути.
        $files = glob($path . DIRECTORY_SEPARATOR . $pattern);
        if (empty($files)) {
            return;
        }

        // Сортируем по времени изменения (сначала новые).
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        // Оставим максимальное кол-во бекапов.
        $unlink = array_slice($files, self::MAX_BACKUPS);

        // Остальное - удалим.
        foreach ($unlink as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Путь хранения бекапов.
     */
    private static function getPath(): string
    {
        $backupPath = Helper::getStorageDir() . DIRECTORY_SEPARATOR . self::FOLDER;
        Helper::checkDirRecursive($backupPath);

        return $backupPath;
    }
}
