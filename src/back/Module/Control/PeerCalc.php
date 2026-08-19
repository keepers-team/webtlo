<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\Enum\ControlPeerLimitPriority;
use KeepersTeam\Webtlo\Enum\DesiredStatusChange;
use KeepersTeam\Webtlo\External\Data\TopicPeers;

final class PeerCalc
{
    private ?int $peerLimit = null;

    public function __construct(private readonly TopicControl $config) {}

    /**
     * Определяем лимит пиров для регулировки в зависимости от настроек для подраздела и торрент клиента.
     */
    public function calcLimit(int $clientControlPeers, int $subsectionControlPeers): int
    {
        // Лимит пиров по умолчанию из настроек.
        $peerLimit = $this->getPeerLimit();

        // Задан лимит для клиента и для раздела.
        if ($clientControlPeers > TopicControl::Disabled && $subsectionControlPeers > TopicControl::Disabled) {
            if ($this->config->peerLimitPriority === ControlPeerLimitPriority::Subsection) {
                $peerLimit = $subsectionControlPeers;
            } else {
                $peerLimit = $clientControlPeers;
            }
        } elseif ($clientControlPeers > TopicControl::Disabled) {
            // Задан лимит только для клиента.
            $peerLimit = $clientControlPeers;
        } elseif ($subsectionControlPeers > TopicControl::Disabled) {
            // Задан лимит только для раздела.
            $peerLimit = $subsectionControlPeers;
        }

        return max($peerLimit, 0);
    }

    /**
     * Определить желаемое состояние раздачи в клиенте, в зависимости от текущих значений и настроек.
     */
    public function determineDesiredState(TopicPeers $topic, int $peerLimit, bool $isSeeding, bool $isForced): DesiredStatusChange
    {
        // Если раздача запущена принудительно извне, ничего не делаем.
        if ($isSeeding && $isForced) {
            return DesiredStatusChange::Nothing;
        }

        // Если выключена опция "запускать раздачи с нулём личей" и у раздачи нет личей, то раздача должна быть остановлена.
        if (!$this->config->seedingWithoutLeechers && $topic->noLeechers()) {
            return $isSeeding
                ? DesiredStatusChange::RandomStop
                : DesiredStatusChange::Nothing;
        }

        // Расчётное значение пиров раздачи.
        $peers = $this->calculateTopicPeers(topic: $topic, isSeeding: $isSeeding);

        // Если текущее количество пиров равно лимиту - то ничего с раздачей не делаем.
        if ($peers === $peerLimit) {
            return DesiredStatusChange::Nothing;
        }

        // Если раздача раздаётся, и лимит не превышает - ничего не делаем.
        if ($isSeeding && $peers < $peerLimit) {
            return DesiredStatusChange::Nothing;
        }

        // Если раздача остановлена и лимит превышает - ничего не делаем.
        if (!$isSeeding && $peers > $peerLimit) {
            return DesiredStatusChange::Nothing;
        }

        // Если состояние раздачи нужно переключить, но разница с лимитом не велика, то применяем рандом.
        if (abs($peers - $peerLimit) <= $this->config->randomApplyCount) {
            return $isSeeding
                ? DesiredStatusChange::RandomStop
                : DesiredStatusChange::RandomStart;
        }

        // Если есть сиды и пиров больше нужного - останавливаем раздачу. В противном случае - запускам.
        return $topic->seeders > 0 && $peers > $peerLimit
            ? DesiredStatusChange::Stop
            : DesiredStatusChange::Start;
    }

    /**
     * Вычисление количества пиров раздачи, в зависимости от выбранных настроек.
     */
    private function calculateTopicPeers(TopicPeers $topic, bool $isSeeding): int
    {
        $control = $this->config;

        // Расчётное значение пиров раздачи.
        $peers = $topic->seeders;

        // Если выбрана опция учёта личей как пиров, то плюсуем их.
        if ($control->countLeechersAsPeers) {
            $peers += $topic->leechers;
        }

        // Если выбрана опция игнорирования части сидов-хранителей на раздаче и они есть.
        if ($topic->keepers > 0 && $control->excludedKeepersCount > 0) {
            // Количество сидов хранителей на раздаче.
            $keepers = $topic->keepers;

            // Если раздача запущена, то вычитаем себя из сидов-хранителей.
            if ($isSeeding) {
                --$keepers;
            }

            // Вычитаем количество исключаемых хранителей.
            $peers -= min($keepers, $control->excludedKeepersCount);
        }

        return max(0, $peers);
    }

    /**
     * Найти в настройках глобальное значение лимита пиров, в т.ч. из интервалов, если они заданы.
     */
    private function getPeerLimit(): int
    {
        if ($this->peerLimit !== null) {
            return $this->peerLimit;
        }

        if (empty($this->config->peersLimitIntervals)) {
            return $this->peerLimit = $this->config->peersLimit;
        }

        // Текущий час (0-23).
        $currentHour = (int) date('G');

        $interval  = new PeerInterval($this->config->peersLimitIntervals);
        $peerLimit = $interval->getCurrentIntervalPeerLimit($currentHour);

        return $this->peerLimit = $peerLimit ?? $this->config->peersLimit;
    }
}
