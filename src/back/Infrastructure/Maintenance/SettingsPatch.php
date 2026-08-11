<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Infrastructure\Maintenance;

use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\External\Data\ForumsResponse;
use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\Storage\Table\Forums;
use Psr\Log\LoggerInterface;

/**
 * Изменение настроек при необходимости.
 */
final class SettingsPatch
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SubForums       $subForums,
        private readonly Settings        $settings,
    ) {}

    /**
     * Проверим хранимые подразделы, на предмет смены имени или пропажи.
     */
    public function updateSubForums(ForumsResponse $forumsResponse): void
    {
        $needRename = $needRemove = [];
        // Перебираем хранимые подразделы.
        foreach ($this->subForums->params as $subForum) {
            $updatedForum = $forumsResponse->getForum(forumId: $subForum->id);

            if ($updatedForum === null) {
                $needRemove[] = $subForum->id;

                $this->logger->warning(
                    'Обнаружен и удалён несуществующий хранимый подраздел: [{id}]: "{name}".',
                    ['id' => $subForum->id, 'name' => $subForum->name]
                );
            } elseif ($updatedForum->name !== $subForum->name) {
                $needRename[] = $updatedForum;

                $this->logger->notice(
                    'Обнаружена смена наименования подраздела [{id}]. "{before}" => "{after}"',
                    ['id' => $subForum->id, 'before' => $subForum->name, 'after' => $updatedForum->name]
                );
            }
        }

        $updated = false;
        if (count($needRename)) {
            $updated = $this->settings->renameSubForums(renamedSubForums: $needRename);
        }

        if (count($needRemove)) {
            $updated = $this->settings->removeSubForum($needRemove);
        }

        if ($updated) {
            $this->settings->saveChanges();
        }
    }

    /**
     * Проверим совпадение имён хранимых подразделов относительно данных в БД.
     */
    public function checkSubForums(ConnectionInterface $con): bool
    {
        $forums = new Forums(con: $con);
        $forums->fillForums(forumIds: $this->subForums->ids);

        $needRename = [];
        // Перебираем хранимые подразделы.
        foreach ($this->subForums->params as $subForum) {
            $updatedForum = $forums->getForum(forumId: $subForum->id);

            if ($updatedForum !== null && $updatedForum->name !== $subForum->name) {
                $needRename[] = $updatedForum;
            }
        }

        if (count($needRename)) {
            $this->settings->renameSubForums(renamedSubForums: $needRename);

            return $this->settings->saveChanges();
        }

        return false;
    }
}
