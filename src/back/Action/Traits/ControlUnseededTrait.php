<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Action\Traits;

use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Enum\DesiredStatusChange;

trait ControlUnseededTrait
{
    private int $unseededStartCounter = 0;

    /**
     * @param string[] $unseededHashes
     */
    public function checkTorrentUnseeded(Torrent $torrent, array $unseededHashes): ?DesiredStatusChange
    {
        if (empty($unseededHashes)) {
            return null;
        }

        // Если не вышли за лимит запушенных раздач, проверяем новые.
        if ($this->unseededStartCounter < $this->options->maxUnseededCount) {
            // Если раздача есть в списке не сидированных, то увеличиваем счётчик.
            if (in_array($torrent->topicHash, $unseededHashes, true)) {
                $this->unseededStartCounter++;

                return $torrent->paused
                    ? DesiredStatusChange::Start
                    : DesiredStatusChange::Nothing;
            }
        }

        return null;
    }

    public function isUnseededEnable(): bool
    {
        return $this->options->daysUntilUnseeded && $this->options->maxUnseededCount;
    }

    public function unseededInitMessage(): void
    {
        if ($this->isUnseededEnable()) {
            $this->logger->info(
                '[Unseeded] Будет запущено до {count} раздач, которые не сидировались как минимум {days} дней.',
                ['count' => $this->options->maxUnseededCount, 'days' => $this->options->daysUntilUnseeded]
            );
        }
    }

    public function unseededCloseMessage(): void
    {
        if ($this->isUnseededEnable()) {
            if ($this->unseededStartCounter > 0) {
                $this->logger->info(
                    '[Unseeded] Запущено {count} раздач, которые не сидировались как минимум {days} дней.',
                    ['count' => $this->unseededStartCounter, 'days' => $this->options->daysUntilUnseeded]
                );
            } else {
                $this->logger->info('[Unseeded] Нет долго не сидируемых раздач, так держать!');
            }
        }
    }

    public function checkUnseededLimit(): bool
    {
        return $this->unseededStartCounter < $this->options->maxUnseededCount;
    }
}
