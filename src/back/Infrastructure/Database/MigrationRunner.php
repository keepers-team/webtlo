<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Database;

use KeepersTeam\Webtlo\Backup;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MigrationRunner
{
    /**
     * Актуальная версия БД.
     *
     * Должна совпадать с init.sql и последним файлом миграции.
     */
    public const DATABASE_VERSION = 15;

    /**
     * @param positive-int $targetVersion
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int             $targetVersion,
        private readonly string          $filesPath,
    ) {}

    /**
     * Применить план миграции.
     * Явно управляет жизненным циклом соединения
     * там, где это требуется (откат версии).
     */
    public function applyMigrationPlan(SQLiteAdapter $db): void
    {
        $plan = $this->plan(con: $db);

        switch ($plan->action) {
            case MigrationAction::UpToDate:
                // Актуально, ничего не делаем.
                break;
            case MigrationAction::Initialize:
                // Создаём схему с нуля.
                $this->initSchema(con: $db);

                break;
            case MigrationAction::MigrateUp:
                // Последовательное поднятие версии до текущей.
                // Бекапим текущую версию.
                Backup::database(path: $db->databasePath, version: $plan->fromVersion);

                // Мигрируем.
                $this->migrateUp(con: $db, startVersion: $plan->fromVersion);

                break;
            case MigrationAction::RestoreBackup:
                // Найден бекап, пробуем откатить.
                // Бекапим текущую версию.
                Backup::database(path: $db->databasePath, version: $plan->fromVersion);

                // Подменяем файл БД, для этого нужно закрыть соединение.
                $db->close();
                if ($plan->backupPath && !copy($plan->backupPath, $db->databasePath)) {
                    throw new RuntimeException(
                        sprintf(
                            'Не удалось восстановить бекап из файла %s',
                            $plan->backupPath
                        )
                    );
                }

                $db->reconnect();
                $this->logger->info(
                    'Восстановлен бекап базы данных, user_version {before} => {after}.',
                    ['before' => $plan->fromVersion, 'after' => $plan->toVersion]
                );

                break;
            case MigrationAction::RecreateSchema:
                // Удалить и пересоздать схему.
                // Бекапим текущую версию.
                Backup::database(path: $db->databasePath, version: $plan->fromVersion);

                // Удаляем файл и создаём схему с нуля.
                $db->close();
                if (file_exists($db->databasePath)) {
                    unlink($db->databasePath);
                }
                $db->reconnect();

                $this->initSchema(con: $db);
                $this->logger->info(
                    'Бекап базы данных не найден, создаём заново, user_version {before} => {after}.',
                    ['before' => $plan->fromVersion, 'after' => $plan->toVersion]
                );

                break;
        }
    }

    /**
     * Построить план миграции. Не мутирует соединение.
     */
    private function plan(ConnectionInterface $con): MigrationPlan
    {
        $current = (int) ($con->queryColumn('PRAGMA user_version') ?? 0);

        if ($current === $this->targetVersion) {
            return new MigrationPlan(
                action     : MigrationAction::UpToDate,
                fromVersion: $current,
                toVersion  : $this->targetVersion
            );
        }

        if ($current === 0) {
            return new MigrationPlan(
                action     : MigrationAction::Initialize,
                fromVersion: $current,
                toVersion  : $this->targetVersion
            );
        }

        if ($current > $this->targetVersion) {
            $backupPath = Backup::findDatabaseBackup(version: $this->targetVersion);

            return new MigrationPlan(
                action     : $backupPath !== null
                    ? MigrationAction::RestoreBackup
                    : MigrationAction::RecreateSchema,
                fromVersion: $current,
                toVersion  : $this->targetVersion,
                backupPath : $backupPath,
            );
        }

        return new MigrationPlan(
            action     : MigrationAction::MigrateUp,
            fromVersion: $current,
            toVersion  : $this->targetVersion
        );
    }

    /**
     * Инициализация таблиц актуальной версии с нуля.
     */
    private function initSchema(ConnectionInterface $con): void
    {
        $file = $this->filesPath . '/schema/init.sql';

        $sql = file_get_contents($file);
        if (!$sql) {
            $this->logger->warning('Ошибка загрузки файла инициализации', ['file' => $file]);

            throw new RuntimeException('Не удалось загрузить файл инициализации таблиц БД.');
        }

        $con->executeQuery(sql: $sql);
    }

    /**
     * Совместимость со старыми версиями базы данных.
     *
     * Все файлы должны соблюдать паттерн наименования: "0000-some-description.sql"
     * где 0000 - новая версия БД, после применения миграции.
     *
     * @param int $startVersion версия БД до применения миграций (текущая)
     */
    private function migrateUp(ConnectionInterface $con, int $startVersion): void
    {
        $currentVersion = $startVersion;

        $migrationPath = $this->filesPath . '/migrations';

        $files = scandir($migrationPath);
        if ($files === false) {
            $this->logger->warning('Ошибка поиска списка миграций', ['path' => $migrationPath]);

            throw new RuntimeException('Ошибка поиска списка миграций.');
        }

        sort($files, SORT_NATURAL);

        foreach ($files as $file) {
            if (!preg_match('/^(\d+)-.*\.sql$/', $file, $m)) {
                continue;
            }

            $version = (int) $m[1];

            if ($version <= $currentVersion) {
                continue;
            }

            $filePath = $migrationPath . '/' . $file;

            $migration = file_get_contents($filePath);
            if (!$migration) {
                $this->logger->warning('Ошибка загрузки файла инициализации', ['file' => $filePath]);

                throw new RuntimeException(sprintf('Пустой файл миграции %s', $file));
            }

            $con->executeQuery(sql: $migration);

            $currentVersion = $version;
        }

        // Проверим, а все ли миграции выполнились.
        if ($currentVersion < $this->targetVersion) {
            $this->logger->error('Миграция базы данных не завершена.', [
                'before' => $startVersion,
                'after'  => $currentVersion,
                'target' => $this->targetVersion,
            ]);
            $this->logger->debug('migration files', $files);

            throw new RuntimeException("Не удалось обновить БД до заданной версии ($this->targetVersion).");
        }

        $this->logger->info(
            'Миграция базы данных завершена успешно, user_version {before} => {after}.',
            ['before' => $startVersion, 'after' => $currentVersion]
        );
    }
}
