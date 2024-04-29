<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use Exception;
use KeepersTeam\Webtlo\DTO\KeysObject;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Legacy\Log;
use PDO;

/** Работа с таблицей маркеров обновлений. */
final class LastUpdate
{
    private ?UpdateStatus $status = null;

    private int   $minTime = 0;
    private array $updates = [];

    public function __construct(
        private readonly array $markers
    ) {
    }

    private function checkMarkersExists(): void
    {
        if (!count($this->markers)) {
            $this->status = UpdateStatus::MISSED;
        }
        if (null === $this->status) {
            $this->fillMarkers();
            $this->checkMarkersCount();
        }
    }

    private function fillMarkers(): void
    {
        $keyObj  = KeysObject::create($this->markers);
        $updates = Db::query_database(
            "SELECT id, ud FROM UpdateTime WHERE id IN ($keyObj->keys)",
            $keyObj->values,
            true,
            PDO::FETCH_KEY_PAIR
        );

        if (count($updates)) {
            $this->updates = $updates;
            $this->minTime = min($updates);
        }
    }

    private function checkMarkersCount(): void
    {
        // Проверим наличие всех маркеров.
        if (count($this->updates) !== count($this->markers)) {
            $this->status = UpdateStatus::MISSED;
        }
    }

    public function getLastCheckStatus(): ?UpdateStatus
    {
        return $this->status;
    }

    public function getLastCheckUpdateTime(): int
    {
        return $this->minTime ?? 0;
    }

    public function addLog(): void
    {
        $log = [
            'countMarkers' => count($this->markers),
            'countUpdates' => count($this->updates),
            'markers'      => $this->markers,
            'updates'      => $this->updates,
        ];
        if ($this->status === UpdateStatus::MISSED) {
            $missed = array_keys(
                array_diff_key(
                    array_fill_keys($this->markers, 0),
                    $this->updates
                )
            );

            $log['missed'] = $missed;

            $missed = array_map(function($markId) {
                $mark = UpdateMark::tryFrom((int)$markId);

                return $mark ? $mark->label() : "Раздачи подраздела №$markId";
            }, $missed);

            Log::append(sprintf('Notice: Отсутствуют маркеры обновления для: %s.', implode(', ', $missed)));
        }
        // TODO loglevel debug
        Log::append(json_encode($log));
    }

    /**
     * Проверить наличие всех маркеров.
     * Убедиться, что минимальная дата обновления меньше текущей на заданный промежуток.
     */
    public function checkMarkersLess(int $seconds = 3600): void
    {
        $this->checkMarkersExists();
        if (null === $this->status) {
            if (time() - $this->minTime < $seconds) {
                $this->status = UpdateStatus::EXPIRED;
            }
        }
    }

    /**
     * Проверить наличие всех маркеров.
     * Убедиться, что минимальная дата обновления больше текущей на заданный промежуток.
     */
    public function checkMarkersAbove(int $seconds = 3600): void
    {
        $this->checkMarkersExists();
        if (null === $this->status) {
            if (time() - $this->minTime > $seconds) {
                $this->status = UpdateStatus::EXPIRED;
            }
        }
    }

    /**
     * Записать timestamp последнего обновления заданного маркера.
     */
    public static function setTime(int $markerId, ?int $updateTime = null): void
    {
        $updateTime ??= time();
        Db::query_database(
            "INSERT INTO UpdateTime (id, ud) SELECT ?,?",
            [$markerId, $updateTime]
        );
    }

    /**
     * Получить timestamp последнего обновления заданного маркера.
     */
    public static function getTime(int $markerId): int
    {
        return Db::query_count(
            "SELECT ud FROM UpdateTime WHERE id = ?",
            [$markerId],
        );
    }

    /**
     * Проверить прошло ли заданное количество секунд с последнего обновления маркера.
     */
    public static function checkUpdateAvailable(int $markerId, int $seconds = 3600): bool
    {
        $updateTime = self::getTime($markerId);

        if (time() - $updateTime < $seconds) {
            // Если время не прошло, запретить обновление.
            return false;
        }

        return true;
    }

    /**
     * Проверить наличие всех нужных маркеров обновления и их актуальность.
     *
     * @throws Exception
     */
    public static function checkReportsSendAvailable(array $cfg): void
    {
        $update = self::checkFullUpdate($cfg);

        if ($update->getLastCheckStatus() === UpdateStatus::MISSED) {
            $update->addLog();
            throw new Exception(
                'Error: Отправка отчётов невозможна. Данные в локальной БД неполные. Выполните полное обновление сведений.'
            );
        }
        if ($update->getLastCheckStatus() === UpdateStatus::EXPIRED) {
            $update->addLog();
            throw new Exception(
                sprintf(
                    'Error: Отправка отчётов невозможна. Данные в локальной БД устарели (%s).',
                    date('d.m.y H:i', $update->getLastCheckUpdateTime())
                )
            );
        }

        // Запишем минимальную дату обновления всех сведений.
        self::setTime(UpdateMark::FULL_UPDATE->value, $update->getLastCheckUpdateTime());
    }

    /**
     * Записать минимальное значение обновления всех нужных маркеров для отправки отчётов.
     */
    public static function checkFullUpdate(array $cfg, bool $checkForum = true): self
    {
        $markers = array_keys($cfg['subsections'] ?? []);
        // Добавим важные маркеры обновлений.
        $markers[] = UpdateMark::FORUM_TREE->value;
        $markers[] = UpdateMark::SUBSECTIONS->value;
        $markers[] = UpdateMark::CLIENTS->value;

        if ($checkForum) {
            $markers[] = UpdateMark::FORUM_SCAN->value;
        }

        // TODO добавить опцию в UI и конфиг.
        $daysUpdateExpire = $cfg['reports']['daysUpdateExpire'] ?? 5;

        $update = new self($markers);
        $update->checkMarkersAbove($daysUpdateExpire * 24 * 3600);

        return $update;
    }
}
