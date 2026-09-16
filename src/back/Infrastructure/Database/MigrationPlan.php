<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Database;

/**
 * Описание того, что нужно сделать с БД.
 */
final class MigrationPlan
{
    public function __construct(
        public readonly MigrationAction $action,
        public readonly int             $fromVersion,
        public readonly int             $toVersion,
        /** Путь к файлу бекапа — только для MigrationAction::RestoreBackup. */
        public readonly ?string         $backupPath = null,
    ) {}
}
