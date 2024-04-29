<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Legacy\Log;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class DB
{
    /** Актуальная версия БД */
    private const DATABASE_VERSION = 13;

    /** Инициализация таблиц актуальной версии. */
    private const INIT_FILE = '9999-init-database.sql';

    /** Название файла БД. */
    private const DATABASE_FILE = 'webtlo.db';

    private static ?self $instance = null;

    public function __construct(public readonly PDO $db)
    {
    }

    public static function create(): DB
    {
        $databasePath = self::getDatabasePath();

        if (null === self::$instance) {
            try {
                // Подключаемся к БД. Создаём кастомную функцию like.
                $pdo = new PDO('sqlite:' . $databasePath);
                $pdo->sqliteCreateFunction('like', [self::class, 'lexa_ci_utf8_like'], 2);

                // Создаём экземпляр класса.
                $db = new DB($pdo);

                // Инциализация/миграция таблиц БД.
                $db->checkDatabaseVersion($databasePath);

                self::$instance = $db;
            } catch (PDOException $e) {
                throw new RuntimeException(
                    sprintf('Не удалось подключиться к БД в "%s", причина: %s', $databasePath, $e)
                );
            }
        }

        return self::$instance;
    }

    public static function getInstance(): self
    {
        if (null === self::$instance) {
            return self::create();
        }

        return self::$instance;
    }

    /** Подготовить запрос и выполнить с параметрами. */
    public function executeStatement(string $sql, array $param = []): PDOStatement
    {
        try {
            $sth = $this->db->prepare($sql);
            if (false === $sth) {
                throw new PDOException('Cant create PDOStatement');
            }

            $sth->execute($param);

            return $sth;
        } catch (Throwable $e) {
            Log::append($sql);
            throw new RuntimeException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    /** Запрос набора строк. */
    public function query(string $sql, array $param = [], int $pdo = PDO::FETCH_ASSOC): array
    {
        $sth = $this->executeStatement($sql, $param);

        return (array)$sth->fetchAll($pdo);
    }

    /** Запрос одной строки. */
    public function queryRow(string $sql, array $param = [], int $pdo = PDO::FETCH_ASSOC): mixed
    {
        $sth = $this->executeStatement($sql, $param);

        return $sth->fetch($pdo);
    }

    /** Запрос одной ячейки. */
    public function queryColumn(string $sql, array $param = []): mixed
    {
        return $this->queryRow($sql, $param, PDO::FETCH_COLUMN);
    }

    /** Запрос count счётчика. */
    public function queryCount(string $sql, array $param = []): int
    {
        return (int)($this->queryColumn($sql, $param) ?? 0);
    }

    /** Запрос количество строк в таблице. */
    public function selectRowsCount(string $table): int
    {
        return $this->queryCount("SELECT COUNT() FROM $table");
    }

    /** Выполнить готовый запрос к БД. */
    private function executeQuery(string $sql): void
    {
        try {
            $this->db->exec($sql);
        } catch (Throwable $e) {
            Log::append($sql);
            throw new RuntimeException($e->getMessage(), (int)$e->getCode(), $e);
        }
    }

    private static function getDatabasePath(): string
    {
        $databaseDirName = Helper::getStorageDir();
        Helper::checkDirRecursive($databaseDirName);

        return $databaseDirName . DIRECTORY_SEPARATOR . self::DATABASE_FILE;
    }

    /**
     * Проверить текущую версию БД и, при необходимости, выполнить инициализацию/миграцию.
     */
    private function checkDatabaseVersion(string $databasePath): void
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

    /** Получить список файлов миграции. */
    private function getFiles(string $sqlPath): array
    {
        // Исключаем из скриптов миграции файл инициализации c нуля.
        $exclude = ['..', '.', self::INIT_FILE];

        return array_values(array_diff(scandir($sqlPath), $exclude));
    }

    /**
     * PHP SQLite case-insensitive LIKE for Unicode strings.
     * https://blog.amartynov.ru/php-sqlite-case-insensitive-like-utf8/
     */
    private static function lexa_ci_utf8_like(string $mask, mixed $value): bool|int
    {
        $mask = str_replace(
            ["%", "_"],
            [".*?", "."],
            preg_quote($mask, "/")
        );
        $mask = "/^$mask$/ui";

        return preg_match($mask, (string)$value);
    }

    public function __destruct()
    {
        $this->query('PRAGMA analysis_limit=400;');
        $this->query('PRAGMA optimize;');
    }
}
