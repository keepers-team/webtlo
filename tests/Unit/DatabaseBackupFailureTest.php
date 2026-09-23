<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Tests\Unit;

use KeepersTeam\Webtlo\Backup;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Infrastructure\Database\MigrationRunner;
use KeepersTeam\Webtlo\Infrastructure\Database\SQLiteAdapter;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * @internal
 *
 * @covers \KeepersTeam\Webtlo\Backup
 */
final class DatabaseBackupFailureTest extends TestCase
{
    private string $tmpDir;
    private string|false $originalStorageDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/webtlo_backup_test_' . uniqid('', true);
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

    public function testDatabasePublishesBackupOnlyAfterSuccessfulCopy(): void
    {
        $source = $this->tmpDir . '/webtlo.db';
        file_put_contents($source, 'database contents');

        Backup::database(path: $source, version: 7);

        $backupPath = $this->tmpDir . '/backup';
        $backups    = glob($backupPath . '/webtlo-v7-*.db');

        self::assertIsArray($backups);
        self::assertCount(1, $backups);
        self::assertSame('database contents', file_get_contents($backups[0]));
        self::assertSame([], glob($backupPath . '/.webtlo-*'));
    }

    public function testDatabasePreservesExistingBackupPermissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Windows does not preserve POSIX file permissions.');
        }

        $source = $this->tmpDir . '/webtlo.db';
        file_put_contents($source, 'updated database contents');

        $backupPath = $this->tmpDir . '/backup';
        $backupFile = $backupPath . '/webtlo-v7-' . date('Y-m-d-H-i') . '.db';
        mkdir($backupPath, 0o777, true);
        file_put_contents($backupFile, 'previous database contents');
        chmod($backupFile, 0o600);

        Backup::database(path: $source, version: 7);

        clearstatcache(true, $backupFile);
        $backupPermissions = fileperms($backupFile);
        self::assertNotFalse($backupPermissions);
        self::assertSame(0o600, $backupPermissions & 0o777);
        self::assertSame('updated database contents', file_get_contents($backupFile));
    }

    public function testDatabaseFailureLeavesNoVisibleOrTemporaryBackup(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            Backup::database(path: $this->tmpDir . '/missing.db', version: 7);
        } finally {
            $backupPath = $this->tmpDir . '/backup';

            self::assertSame([], glob($backupPath . '/webtlo-*.db'));
            self::assertSame([], glob($backupPath . '/.webtlo-*'));
        }
    }

    public function testRecreateSchemaPreservesDatabaseWhenBackupCannotBePublished(): void
    {
        $databasePath = $this->tmpDir . '/webtlo.db';
        $pdo          = new PDO('sqlite:' . $databasePath);
        $pdo->exec('PRAGMA user_version = 2');
        $pdo->exec('CREATE TABLE preserved_data (value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO preserved_data (value) VALUES ('must survive')");

        $before = file_get_contents($databasePath);
        self::assertIsString($before);

        $backupPath = $this->tmpDir . '/backup';
        mkdir($backupPath, 0o777, true);
        foreach ([time(), time() + 60] as $timestamp) {
            mkdir($backupPath . '/webtlo-v2-' . date('Y-m-d-H-i', $timestamp) . '.db');
        }

        $runner = new MigrationRunner(
            logger       : new NullLogger(),
            targetVersion: 1,
            filesPath    : $this->tmpDir,
        );

        $this->expectException(RuntimeException::class);

        try {
            $runner->applyMigrationPlan($this->createAdapter($databasePath, $pdo));
        } finally {
            self::assertSame($before, file_get_contents($databasePath));
            $versionStatement = $pdo->query('PRAGMA user_version');
            self::assertNotFalse($versionStatement);
            self::assertSame(2, (int) $versionStatement->fetchColumn());

            $dataStatement = $pdo->query('SELECT value FROM preserved_data');
            self::assertNotFalse($dataStatement);
            self::assertSame('must survive', $dataStatement->fetchColumn());
            self::assertSame([], glob($backupPath . '/.webtlo-*'));
        }
    }

    private function createAdapter(string $databasePath, PDO $pdo): SQLiteAdapter
    {
        $class       = new ReflectionClass(SQLiteAdapter::class);
        $adapter     = $class->newInstanceWithoutConstructor();
        $constructor = new ReflectionMethod(SQLiteAdapter::class, '__construct');
        $constructor->setAccessible(true);
        $constructor->invoke($adapter, $databasePath, new NullLogger(), $pdo);

        return $adapter;
    }
}
