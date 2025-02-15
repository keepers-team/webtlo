<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

use DateTimeImmutable;
use KeepersTeam\Webtlo\Clients\Data\Torrent;
use KeepersTeam\Webtlo\Config\TopicControl as ConfigControl;
use KeepersTeam\Webtlo\Enum\DesiredStatusChange;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Storage\Table\UpdateTime;
use Psr\Log\LoggerInterface;

/**
 * Модуль для учёта и проверки давно не сидируемых раздач.
 */
final class Unseeded
{
    private ?bool $moduleEnable = null;

    /**
     * @var int счётчик запускаемых раздач
     */
    private int $startCounter = 0;
    /**
     * @var int общее количество не сидируемых раздач, в проверенных хранимых подразделах
     */
    private int $totalCount = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigControl   $topicControl,
        private readonly UpdateTime      $updateTime,
    ) {}

    /**
     * Добавить в счётчик количество найденных не сидируемых раздач.
     */
    public function updateTotal(int $count): void
    {
        $this->totalCount += $count;
    }

    /**
     * @param string[] $unseededHashes
     */
    public function checkTorrent(Torrent $torrent, array $unseededHashes): ?DesiredStatusChange
    {
        if (empty($unseededHashes)) {
            return null;
        }

        // Если не вышли за лимит запушенных раздач, проверяем новые.
        if ($this->startCounter < $this->topicControl->maxUnseededCount) {
            // Если раздача есть в списке не сидированных, то увеличиваем счётчик.
            if (in_array($torrent->topicHash, $unseededHashes, true)) {
                ++$this->startCounter;

                return $torrent->paused
                    ? DesiredStatusChange::Start
                    : DesiredStatusChange::Nothing;
            }
        }

        return null;
    }

    public function init(): void
    {
        if ($this->isEnable()) {
            $this->logger->info(
                '[Unseeded] Будет запущено до {count} раздач, которые не сидировались как минимум {days} дней.',
                ['count' => $this->topicControl->maxUnseededCount, 'days' => $this->topicControl->daysUntilUnseeded]
            );
        }
    }

    public function close(): void
    {
        if ($this->isEnable()) {
            if ($this->startCounter > 0) {
                $this->logger->info(
                    '[Unseeded] Запущено {count} из {total} раздач, которые не сидировались как минимум {days} дней.',
                    [
                        'count' => $this->startCounter,
                        'total' => $this->totalCount,
                        'days'  => $this->topicControl->daysUntilUnseeded,
                    ]
                );
            } else {
                // Если нет раздач, которые нужно запускать, записываем дату и не проверяем до следующего дня.
                $this->updateTime->setMarkerTime(marker: UpdateMark::UNSEEDED);
                $this->logger->info('[Unseeded] Нет долго не сидируемых раздач, так держать! (Отключёно до следующего дня)');
            }
        }
    }

    public function checkLimit(): bool
    {
        return $this->isEnable()
            && $this->startCounter < $this->topicControl->maxUnseededCount;
    }

    private function isEnable(): bool
    {
        if ($this->moduleEnable !== null) {
            return $this->moduleEnable;
        }

        return $this->moduleEnable =
            $this->topicControl->daysUntilUnseeded
            && $this->topicControl->maxUnseededCount
            && $this->checkTodayEnabled();
    }

    /**
     * Проверяем, нужно ли сегодня искать раздачи.
     */
    private function checkTodayEnabled(): bool
    {
        $lastCheck = $this->updateTime->getMarkerTime(marker: UpdateMark::UNSEEDED);

        return (new DateTimeImmutable())->setTime(0, 0) > $lastCheck;
    }
}
