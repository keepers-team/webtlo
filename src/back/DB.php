<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Storage\Traits;
use PDO;
use PDOException;
use RuntimeException;

final class DB
{
    use Traits\DbClearTables;
    use Traits\DbDataSet;
    use Traits\DbMigration;
    use Traits\DbQuery;

    /** Актуальная версия БД */
    private const DATABASE_VERSION = 15;

    /** Инициализация таблиц актуальной версии. */
    private const INIT_FILE = '9999-init-database.sql';

    /** Название файла БД. */
    private const DATABASE_FILE = 'webtlo.db';

    private static ?self $instance = null;

    public function __construct(public readonly PDO $db) {}

    public static function create(): DB
    {
        $databasePath = self::getDatabasePath();

        if (self::$instance === null) {
            try {
                // Подключаемся к БД. Создаём кастомную функцию like.
                $pdo = new PDO('sqlite:' . $databasePath);
                $pdo->sqliteCreateFunction('like', [self::class, 'lexa_ci_utf8_like'], 2);

                // Создаём экземпляр класса.
                $db = new DB($pdo);

                // Инициализация/миграция таблиц БД.
                $db->checkDatabaseVersion($databasePath);

                self::$instance = $db;
            } catch (PDOException $e) {
                throw new RuntimeException(
                    sprintf('Не удалось подключиться к БД в "%s", причина: %s', $databasePath, $e)
                );
            }
        }

        // Очистка таблиц от неактуальных записей.
        $instance = self::$instance;
        $instance->clearTables();

        return $instance;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            return self::create();
        }

        return self::$instance;
    }

    private static function getDatabasePath(): string
    {
        $databaseDirName = Helper::getStorageDir();
        Helper::checkDirRecursive($databaseDirName);

        return $databaseDirName . DIRECTORY_SEPARATOR . self::DATABASE_FILE;
    }

    /**
     * PHP SQLite case-insensitive LIKE for Unicode strings.
     *
     * https://blog.amartynov.ru/php-sqlite-case-insensitive-like-utf8/
     */
    private static function lexa_ci_utf8_like(string $mask, mixed $value): bool|int
    {
        $mask = str_replace(
            ['%', '_'],
            ['.*?', '.'],
            preg_quote($mask, '/')
        );
        $mask = "/^$mask$/ui";

        return preg_match($mask, (string) $value);
    }

    public function __destruct()
    {
        $this->query('PRAGMA analysis_limit=400;');
        $this->query('PRAGMA optimize;');
    }
}
