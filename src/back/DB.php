<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Config\AverageSeeds;
use KeepersTeam\Webtlo\Storage\Traits;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class DB
{
    use Traits\DbClearTables;
    use Traits\DbDataSet;
    use Traits\DbMigration;
    use Traits\DbQuery;

    /** Актуальная версия БД */
    protected const DATABASE_VERSION = 15;

    /** Инициализация таблиц актуальной версии. */
    protected const INIT_FILE = '9999-init-database.sql';

    /** Название файла БД. */
    private const DATABASE_FILE = 'webtlo.db';

    public function __construct(
        public readonly PDO             $db,
        public readonly LoggerInterface $logger,
    ) {}

    public static function connect(
        LoggerInterface $logger,
        AverageSeeds $averageSeeds,
    ): DB {
        $databasePath = self::getDatabasePath();

        try {
            // Подключаемся к БД. Создаём кастомную функцию like.
            $pdo = new PDO('sqlite:' . $databasePath);
            $pdo->sqliteCreateFunction('like', [self::class, 'lexa_ci_utf8_like'], 2);

            // Создаём экземпляр класса.
            $db = new DB(db: $pdo, logger: $logger);

            // Инициализация/миграция таблиц БД.
            $db->checkDatabaseVersion(databasePath: $databasePath);
        } catch (PDOException $e) {
            $logger->emergency('Ошибка инициализации БД.', ['path' => $databasePath, 'exception' => $e]);

            throw new RuntimeException(
                sprintf(
                    'Не удалось подключиться к БД в "%s", причина: %s',
                    $databasePath,
                    $e->getMessage()
                )
            );
        }

        // Очистка таблиц от неактуальных записей.
        $db->clearTables($averageSeeds->historyExpiryDays);

        return $db;
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
