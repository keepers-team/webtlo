<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use DateTimeImmutable;
use DateTimeInterface;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Helper;
use Psr\Log\LoggerInterface;

final class MarkersUpdate
{
    private ?UpdateStatus $status = null;

    private ?DateTimeImmutable $min = null;

    public function __construct(
        /** @var int[] Маркеры, которые нужно проверить. */
        public readonly array $markers,
        /** @var array<int, int> Метки времени обновления маркеров. */
        public readonly array $timestamps,
    ) {}

    /**
     * Отформатировать дату обновления маркеров.
     *
     * @return array{}|array<int, string>
     */
    public function getFormattedMarkers(): array
    {
        return array_map(function($el) {
            return Helper::makeDateTime($el)->format(DateTimeInterface::ATOM);
        }, $this->timestamps);
    }

    /**
     * Убедиться, что минимальная дата обновления меньше текущей на заданный промежуток.
     */
    public function checkMarkersLess(int $seconds = 3600): void
    {
        $this->checkMarkersCount();

        if ($this->status === null) {
            $min = $this->getMinUpdate();
            if (time() - $min->getTimestamp() < $seconds) {
                $this->status = UpdateStatus::EXPIRED;
            }
        }
    }

    /**
     * Убедиться, что минимальная дата обновления больше текущей на заданный промежуток.
     */
    public function checkMarkersAbove(int $seconds = 3600): void
    {
        $this->checkMarkersCount();

        if ($this->status === null) {
            $min = $this->getMinUpdate();
            if (time() - $min->getTimestamp() > $seconds) {
                $this->status = UpdateStatus::EXPIRED;
            }
        }
    }

    public function getMinUpdate(): DateTimeImmutable
    {
        if ($this->min !== null) {
            return $this->min;
        }

        $minTimestamp = 0;
        if (!empty($this->timestamps)) {
            $minTimestamp = min($this->timestamps);
        }

        return $this->min = Helper::makeDateTime($minTimestamp);
    }

    public function getLastCheckStatus(): ?UpdateStatus
    {
        return $this->status;
    }

    /**
     * Записать в лог данные маркеров.
     */
    public function addLogRecord(LoggerInterface $logger): void
    {
        $log = [
            'countMarkers' => count($this->markers),
            'countUpdates' => count($this->timestamps),
            'markers'      => $this->markers,
            'updates'      => $this->timestamps,
        ];

        if ($this->status === UpdateStatus::MISSED) {
            $missed = array_keys(
                array_diff_key(
                    array_fill_keys($this->markers, 0),
                    $this->timestamps
                )
            );

            $log['missed'] = $missed;

            $missed = array_map(function($markId) {
                $mark = UpdateMark::tryFrom((int) $markId);

                return $mark ? $mark->label() : "Раздачи подраздела №$markId";
            }, $missed);

            $logger->notice('Отсутствуют маркеры обновления для: {missed}', ['missed' => implode(', ', $missed)]);
        }
        $logger->debug((string) json_encode($log));
    }

    private function checkMarkersCount(): void
    {
        // Если не заданы маркеры - считаем, что ошибка.
        if ($this->status === null) {
            if (!count($this->markers)) {
                $this->status = UpdateStatus::MISSED;
            }
        }

        // Проверим наличие всех маркеров.
        if ($this->status === null) {
            if (count($this->markers) !== count($this->timestamps)) {
                $this->status = UpdateStatus::MISSED;
            }
        }
    }
}
