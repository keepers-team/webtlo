<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Database;

use KeepersTeam\Webtlo\Config\AverageSeeds;
use KeepersTeam\Webtlo\Helper;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class SQLiteAdapter implements ConnectionInterface
{
    use DatabaseQueryTrait;

    /** Название файла БД. */
    private const DATABASE_FILE = 'webtlo.db';

    public function __construct(
        public readonly PDO             $pdo,
        public readonly LoggerInterface $logger,
    ) {}

    public static function connect(
        LoggerInterface $logger,
        AverageSeeds $averageSeeds,
    ): self {
        $databasePath = Helper::getStorageSubFolderPath(file: self::DATABASE_FILE);

        try {
            // Подключаемся к БД. Создаём кастомную функцию like.
            $pdo = new PDO('sqlite:' . $databasePath);
            $pdo->sqliteCreateFunction('like', [self::class, 'lexa_ci_utf8_like'], 2);

            // Создаём экземпляр класса.
            $db = new self(pdo: $pdo, logger: $logger);

            $migrator = new MigrationRunner(
                logger       : $logger,
                targetVersion: MigrationRunner::DATABASE_VERSION,
                databasePath : $databasePath,
                filesPath    : Helper::getProjectRoot() . '/database',
            );

            // Инициализация/миграция таблиц БД.
            $migrator->migrate(con: $db);
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
        $cleaner = new Cleaner(
            logger        : $logger,
            keepDataPeriod: $averageSeeds->historyExpiryDays
        );
        $cleaner->clearTables(con: $db);

        return $db;
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
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
