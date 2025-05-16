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

    public static function database(string $path, int $version): void
    {
        $backupName = sprintf('webtlo-v%d-%s.db', $version, date('Y-m-d-H-i'));
        $backupPath = self::getPath();
        $backupFile = $backupPath . DIRECTORY_SEPARATOR . $backupName;

        // Бекапим БД.
        copy($path, $backupFile);

        // Удаляем лишние бекапы.
        self::clearBackups($backupPath, 'webtlo-*.db');
    }

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
        $files = glob($path . DIRECTORY_SEPARATOR . $pattern);
        if (empty($files)) {
            return;
        }

        // Сортируем от свежих к старым и удаляем самые старые.
        $matches = [];
        foreach ($files as $file) {
            $matches[filemtime($file)] = $file;
        }
        krsort($matches);

        // Оставим максимальное кол-во бекапов.
        $unlink = array_slice($matches, self::MAX_BACKUPS);

        // Остальное - удалим.
        foreach ($unlink as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }
}
