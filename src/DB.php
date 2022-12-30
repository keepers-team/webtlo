<?php

namespace KeepersTeam\Webtlo;

use Exception;
use Monolog\Logger;
use PDO;
use PDOException;

class DB
{
    private static string $databaseFilename = 'webtlo.db';
    private static int $databaseVersion = 9;

    public function query_database(string $sql, array $param = [], bool $fetch = false, int $pdo = PDO::FETCH_ASSOC): bool|array
    {
        $this->db->sqliteCreateFunction('like', 'self::lexa_ci_utf8_like', 2);
        $sth = $this->db->prepare($sql);
        if ($this->db->errorCode() != '0000') {
            $error = $this->db->errorInfo();
            throw new Exception('SQL ошибка: ' . $error[2]);
        }
        $sth->execute($param);
        return $fetch ? $sth->fetchAll($pdo) : true;
    }

    // https://blog.amartynov.ru/php-sqlite-case-insensitive-like-utf8/
    public static function lexa_ci_utf8_like($mask, $value)
    {
        $mask = str_replace(
            ["%", "_"],
            [".*?", "."],
            preg_quote($mask, "/")
        );
        $mask = "/^$mask$/ui";
        return preg_match($mask, $value);
    }

    public function combine_set($set)
    {
        foreach ($set as $id => &$value) {
            $value = array_map(function ($e) {
                return is_numeric($e) ? $e : $this->db->quote($e);
            }, $value);
            $value = (empty($value['id']) ? "$id," : "") . implode(',', $value);
        }
        return 'SELECT ' . implode(' UNION ALL SELECT ', $set);
    }

    public function cleanup_seeds(int $avgSeedersPeriodOutdated = 7)
    {
        // данные о сидах устарели
        $avgSeedersPeriodOutdatedSeconds = $avgSeedersPeriodOutdated * 86400;
        $this->query_database(
            "DELETE FROM Topics WHERE ss IN (SELECT id FROM UpdateTime WHERE strftime('%s', 'now') - ud > CAST(:ud as INTEGER)) AND pt <> 2
    OR strftime('%s', 'now') - (SELECT ud FROM UpdateTime WHERE id = 9999) > CAST(:ud as INTEGER) AND pt = 2",
            [':ud' => $avgSeedersPeriodOutdatedSeconds]
        );
        $this->query_database(
            "DELETE FROM UpdateTime WHERE strftime('%s', 'now') - ud > CAST(? as INTEGER)",
            [$avgSeedersPeriodOutdatedSeconds]
        );
    }

    /**
     * объединение нескольких запросов на получение данных
     */
    public function unionQuery(array $source): bool|string
    {
        $values = [];
        foreach ($source as &$row) {
            if (!is_array($row)) {
                return false;
            }
            $row = array_map(
                function ($e) {
                    return is_numeric($e) ? $e : $this->db->quote($e);
                },
                $row
            );
            $values[] = implode(',', $row);
        }
        return 'SELECT ' . implode(' UNION ALL SELECT ', $values);
    }

    private function __construct(private readonly Logger $logger, private readonly PDO $db)
    {
    }

    public static function create(Logger $logger, string $databaseDirname): DB|false
    {
        $databasePath = $databaseDirname . DIRECTORY_SEPARATOR . DB::$databaseFilename;
        if (!file_exists($databaseDirname)) {
            if (!Utils::mkdir_recursive($databaseDirname)) {
                $logger->emergency('Failed to create directory for database', ['dir' => $databaseDirname]);
                return false;
            }
        }
        try {
            $db = new PDO('sqlite:' . $databasePath);
        } catch (PDOException $e) {
            $logger->emergency('Unable to create DB connection', ['path' => $databasePath, 'error' => $e]);
            return false;
        }
        $instance = new DB($logger, $db);

        if ($instance->isProperlyMigrated()) {
            return $instance;
        } else {
            $logger->emergency('Database is not migrated. Run "php manage.php migrate" before starting app');
            return false;
        }
    }

    public static function migrate(Logger $logger, string $databaseDirname): bool
    {
        // FIXME: Add proper migration
        return false;
    }

    private function hasTables(): bool
    {
        /** @noinspection SqlResolve */
        $query = "SELECT name FROM sqlite_schema WHERE type = 'table' AND name NOT LIKE 'sqlite_%';";
        try {
            $result = $this->query_database($query, [], true);
        } catch (Exception $e) {
            $this->logger->error("Can't fetch tables", ['error' => $e]);
            return false;
        }
        $tables = array_map(fn ($row): string => $row['name'], $result);
        $found = !empty($tables);
        if (!$found) {
            $this->logger->warning("No tables found, possible fresh install");
        }
        $this->logger->debug("Found existing tables", $tables);
        return $found;
    }

    private function getVersion(): int|false
    {
        try {
            $result = $this->query_database('PRAGMA user_version', [], true);
            $version = $result[0]['user_version'];
        } catch (Exception $e) {
            $this->logger->warning("Can't fetch version", ['error' => $e]);
            return false;
        }
        $this->logger->debug("Found metadata", ['version' => $version]);

        return $version;
    }

    private function isProperlyMigrated(): bool
    {
        $populated = $this->hasTables();
        $version = $this->getVersion();
        return $populated && $version !== false && $version === self::$databaseVersion;
    }
}
