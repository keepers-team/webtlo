<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Enum\UpdateStatus;
use KeepersTeam\Webtlo\Helper;

final class MarkersUpdate
{
    private ?UpdateStatus $status = null;

    private ?DateTimeImmutable $min = null;

    public function __construct(
        /** @var int[] Маркеры, которые нужно проверить. */
        public readonly array $markers,
        /** @var int[] Метки времени обновления маркеров. */
        public readonly array $timestamps,
    ) {
    }

    /**
     * Убедиться, что минимальная дата обновления меньше текущей на заданный промежуток.
     */
    public function checkMarkersLess(int $seconds = 3600): void
    {
        $this->checkMarkersCount();

        if (null === $this->status) {
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

        if (null === $this->status) {
            $min = $this->getMinUpdate();
            if (time() - $min->getTimestamp() > $seconds) {
                $this->status = UpdateStatus::EXPIRED;
            }
        }
    }

    public function getMinUpdate(): ?DateTimeImmutable
    {
        if (null !== $this->min) {
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

    private function checkMarkersCount(): void
    {
        // Если не заданы маркеры - считаем, что ошибка.
        if (null === $this->status) {
            if (!count($this->markers)) {
                $this->status = UpdateStatus::MISSED;
            }
        }

        // Проверим наличие всех маркеров.
        if (null === $this->status) {
            if (count($this->markers) !== count($this->timestamps)) {
                $this->status = UpdateStatus::MISSED;
            }
        }
    }
}
