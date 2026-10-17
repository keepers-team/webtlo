<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Integration;

use KeepersTeam\Webtlo\Backup;
use KeepersTeam\Webtlo\Config\AverageSeeds;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Infrastructure\Database\SQLiteAdapter;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @internal
 *
 * @coversNothing
 */
final class DatabaseBackupRetentionTest extends TestCase
{
    private string $tmpDir;
    private string|false $originalStorageDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/webtlo_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0o777, true);

        $this->originalStorageDir = getenv('WEBTLO_DIR');
        putenv('WEBTLO_DIR=' . $this->tmpDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalStorageDir === false) {
            putenv('WEBTLO_DIR');
        } else {
            putenv('WEBTLO_DIR=' . $this->originalStorageDir);
        }

        if (is_dir($this->tmpDir)) {
            Helper::removeDirRecursive($this->tmpDir);
        }
    }

    /**
     * Регрессия: при откате версии БД (current > target), если выбранный
     * для восстановления файл оказывался самым старым из пяти,
     * ротация удаляла его, и последующий copy() падал с RuntimeException.
     *
     * Сценарий специально воспроизводит этот случай:
     *  - webtlo-v15-restore.db — самый старый (mtime = baseTime);
     *  - webtlo-v16-old-1..4.db — новее на 1..4 секунды;
     *  - текущая БД — v16, поэтому plan() выбирает RestoreBackup.
     */
    public function testRestoreSkipsRotationAndKeepsAllBackups(): void
    {
        $databasePath = $this->tmpDir . '/webtlo.db';
        $this->createDatabase(path: $databasePath, version: 16, marker: 'current');

        $backupPath = $this->backupPath('webtlo-v15-restore.db');
        $this->createDatabase(path: $backupPath, version: 15, marker: 'restore');

        $baseTime = time() - 600;
        touch($backupPath, $baseTime);

        foreach (range(1, 4) as $index) {
            $path = $this->backupPath(sprintf('webtlo-v16-old-%d.db', $index));
            copy($backupPath, $path);
            touch($path, $baseTime + $index);
        }

        self::assertSame($backupPath, Backup::findDatabaseBackup(version: 15));

        $database = SQLiteAdapter::create(
            logger      : new NullLogger(),
            averageSeeds: new AverageSeeds(
                enableHistory    : false,
                historyDays      : 1,
                historyExpiryDays: 1,
            ),
        );

        // Восстановление прошло успешно.
        self::assertSame('restore', $database->queryColumn('SELECT marker FROM TestMarker'));

        // Выбранный для восстановления бекап не удалён — ротация при откате не выполнялась.
        self::assertFileExists($backupPath);

        // Ранее существовавшие бекапы также сохранены (ротация не тронула ни один файл).
        foreach (range(1, 4) as $index) {
            self::assertFileExists(
                $this->backupPath(sprintf('webtlo-v16-old-%d.db', $index))
            );
        }

        // Новый бекап текущей (большей) версии создан — он пригодится при повторном апгрейде.
        $newBackup = Backup::findDatabaseBackup(version: 16);
        self::assertNotNull($newBackup);
        self::assertNotSame($this->backupPath('webtlo-v16-old-4.db'), $newBackup);

        // 5 исходных + 1 новый бекап отката = 6 (ротации нет).
        self::assertCount(6, $this->databaseBackupFiles());

        $database->close();
    }

    public function testDatabaseBackupWithoutRotationKeepsAllExistingBackups(): void
    {
        $sourcePath = $this->tmpDir . '/webtlo.db';
        file_put_contents($sourcePath, 'current');

        foreach (range(1, 5) as $index) {
            $path = $this->backupPath(sprintf('webtlo-v15-old-%d.db', $index));
            file_put_contents($path, "backup $index");
        }

        // Бекапим без ротации, должно быть 6 файлов.
        Backup::database(path: $sourcePath, version: 16, rotate: false);
        self::assertCount(6, $this->databaseBackupFiles());

        // Бекапим ещё раз с ротацией.
        Backup::database(path: $sourcePath, version: 16);
        self::assertCount(5, $this->databaseBackupFiles());
    }

    public function testDatabaseBackupRotationStillRemovesOldestBackup(): void
    {
        $sourcePath = $this->tmpDir . '/webtlo.db';
        file_put_contents($sourcePath, 'new backup');

        $baseTime = time() - 600;
        foreach (range(1, 5) as $index) {
            $path = $this->backupPath(sprintf('webtlo-v15-old-%d.db', $index));
            file_put_contents($path, "backup $index");
            touch($path, $baseTime + $index);
        }

        Backup::database(path: $sourcePath, version: 16);

        self::assertFileDoesNotExist($this->backupPath('webtlo-v15-old-1.db'));
        self::assertCount(5, $this->databaseBackupFiles());

        $newBackup = Backup::findDatabaseBackup(version: 16);
        self::assertNotNull($newBackup);
        self::assertSame('new backup', file_get_contents($newBackup));
    }

    private function backupPath(string $filename): string
    {
        $path = $this->tmpDir . '/backup';
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        return $path . '/' . $filename;
    }

    /**
     * @return string[]
     */
    private function databaseBackupFiles(): array
    {
        $files = glob($this->tmpDir . '/backup/webtlo-*.db');
        self::assertNotFalse($files);

        return $files;
    }

    private function createDatabase(string $path, int $version, string $marker): void
    {
        $schema = file_get_contents(Helper::getProjectRoot() . '/database/schema/init.sql');
        self::assertNotFalse($schema);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->exec($schema);

        $pdo->exec('CREATE TABLE TestMarker (marker VARCHAR NOT NULL)');
        $statement = $pdo->prepare('INSERT INTO TestMarker (marker) VALUES (?)');

        self::assertNotFalse($statement);
        $statement->execute([$marker]);

        $pdo->exec("PRAGMA user_version = $version");
    }
}
