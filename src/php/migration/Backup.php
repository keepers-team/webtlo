<?php

use KeepersTeam\Webtlo\Helper;

/**
 * Бекапим конфиг.
 */
class Backup
{
    /** Максимальное кол-во бекапов каждого вида */
    private const MAX_BACKUPS = 5;
    private const FOLDER = 'backup';

    /**
     * @throws Exception
     */
    public static function config(string $path, int $version): void
    {
        $backupName = sprintf('config-v%d-%s.ini', $version, date('Y-m-d-H-i'));
        $backupPath = self::getPath();
        $backupFile = $backupPath . DIRECTORY_SEPARATOR . $backupName;

        // Бекапим конфиг.
        copy($path, $backupFile);

        // Удаляем лишние бекапы.
        self::clearBackups($backupPath, '/config-(.*)\.ini/');
    }

    /**
     * @throws Exception
     */
    public static function database(string $path, int $version): void
    {
        $backupName = sprintf('webtlo-v%d-%s.db', $version, date('Y-m-d-H-i'));
        $backupPath = self::getPath();
        $backupFile = $backupPath . DIRECTORY_SEPARATOR . $backupName;

        // Бекапим БД.
        copy($path, $backupFile);

        // Удаляем лишние бекапы.
        self::clearBackups($backupPath, '/webtlo-(.*)\.db/');
    }

    /**
     * @throws Exception
     */
    private static function getPath(): string
    {
        $backupPath = Helper::getStorageDir() . DIRECTORY_SEPARATOR . self::FOLDER;
        Helper::checkDirRecursive($backupPath);
        return $backupPath;
    }

    /**
     * Удаляем лишние конфиги.
     */
    private static function clearBackups(string $path, string $pattern): void
    {
        // Все файлы по указанному пути.
        $files = array_diff(scandir($path, SCANDIR_SORT_DESCENDING), ['..', '.']);

        // Фильтр по паттерну имени.
        $matches = preg_grep($pattern, $files);

        // Оставим максимальное кол-во бекапов.
        $matches = array_slice($matches, self::MAX_BACKUPS);

        // Остальное - удалим.
        foreach ($matches as $file) {
            $filePath = $path . DIRECTORY_SEPARATOR . $file;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }
}
