<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Backup;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\TIniFileEx;

final class ConfigMigration
{
    private int $version;

    public function __construct(private readonly TIniFileEx $ini)
    {
        $this->version = (int) $this->ini->read('other', 'user_version', Settings::ACTUAL_VERSION);
    }

    public function run(): void
    {
        if ($this->version >= Settings::ACTUAL_VERSION) {
            return;
        }

        $this->migrate3();

        $this->ini->write('other', 'user_version', $this->version);
        $this->ini->writeFile();
    }

    /**
     * Миграция списка исключённых подразделов.
     *
     * 2.5.0 от ~06.07.2023
     * хеш (696bc8bb82530a56d659d9dbb604a7f65f449af7).
     */
    private function migrate3(): void
    {
        if ($this->version >= 3) {
            return;
        }

        $ini = $this->ini;

        // Сохраним бекап конфига при изменении версии.
        Backup::config($ini->getFile(), $this->version);

        $excludeForums = $ini->read('reports', 'exclude');
        $subsections   = $ini->read('sections', 'subsections');

        if (empty($excludeForums) || empty($subsections)) {
            return;
        }

        // Пробуем вытащить ид подразделов из опции исключения из отчётов.
        $excludeForums = array_filter(explode(',', trim($excludeForums)));
        $excludeForums = array_map('intval', array_unique($excludeForums));

        // Удаляём ненужный ключик.
        $ini->deleteKey('reports', 'exclude');

        $subsections = array_filter(explode(',', trim($subsections)));
        $subsections = array_map('intval', array_unique($subsections));

        $checkedForumIDs = [];
        foreach ($excludeForums as $forumId) {
            $forumId = (int) $forumId;

            if (in_array($forumId, $subsections)) {
                $ini->write($forumId, 'exclude', 1);

                $checkedForumIDs[] = $forumId;
            }
        }

        if (count($checkedForumIDs)) {
            sort($checkedForumIDs);

            $ini->write('reports', 'exclude_forums_ids', implode(',', $checkedForumIDs));
        }

        $this->version = 3;
    }
}
