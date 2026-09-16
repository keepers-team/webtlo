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

    private ?PDO $pdo;

    private function __construct(
        public readonly string           $databasePath,
        private readonly LoggerInterface $logger,
        PDO                              $pdo,
    ) {
        $this->pdo = $pdo;
    }

    public function __destruct()
    {
        if ($this->pdo === null) {
            return;
        }

        $this->query('PRAGMA analysis_limit=400;');
        $this->query('PRAGMA optimize;');
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Соединение с БД не установлено (было закрыто).');
        }

        return $this->pdo;
    }

    public function close(): void
    {
        // Обнуляем ссылку, чтобы освободить файл БД (важно для подмены файла).
        $this->pdo = null;
    }

    public function reconnect(): void
    {
        if ($this->pdo !== null) {
            // Уже подключены — ничего не делаем.
            return;
        }

        $this->pdo = self::createPdo(databasePath: $this->databasePath);
    }

    public static function create(
        LoggerInterface $logger,
        AverageSeeds    $averageSeeds,
    ): self {
        $databasePath = Helper::getStorageSubFolderPath(file: self::DATABASE_FILE);

        try {
            // Создаём экземпляр класса.
            $db = new self(
                databasePath: $databasePath,
                logger      : $logger,
                pdo         : self::createPdo(databasePath: $databasePath),
            );

            $migrator = new MigrationRunner(
                logger       : $logger,
                targetVersion: MigrationRunner::DATABASE_VERSION,
                filesPath    : Helper::getProjectRoot() . '/database',
            );

            // Применяем план миграции.
            $migrator->applyMigrationPlan(db: $db);
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

    /**
     * Создать новое PDO-соединение с зарегистрированной функцией like.
     */
    private static function createPdo(string $databasePath): PDO
    {
        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->sqliteCreateFunction('like', [self::class, 'lexa_ci_utf8_like'], 2);

        return $pdo;
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
}
