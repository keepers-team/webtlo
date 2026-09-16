<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Database;

enum MigrationAction
{
    /** БД актуальна, ничего делать не нужно. */
    case UpToDate;
    /** БД пустая — инициализируем схему с нуля. */
    case Initialize;
    /** Обычная инкрементальная миграция вверх. */
    case MigrateUp;
    /** Откат: восстановить бекап целевой версии. */
    case RestoreBackup;
    /** Откат: бекапа нет — пересоздать схему с нуля. */
    case RecreateSchema;
}
