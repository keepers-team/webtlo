<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Control;

use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\Enum\DesiredStatusChange;
use KeepersTeam\Webtlo\External\Data\TopicPeers;

final class PeerCalc
{
    /**
     * @param array<string, mixed> $clientProps
     */
    public static function getClientLimit(array $clientProps): int
    {
        return ($clientProps['control_peers'] !== '') ? (int)$clientProps['control_peers'] : -2;
    }

    /**
     * @param array<string, mixed>[] $config
     */
    public static function getForumLimit(array $config, int|string $group): int
    {
        $subControlPeers = $config['subsections'][$group]['control_peers'] ?? -2;

        return ($subControlPeers !== '') ? (int)$subControlPeers : -2;
    }

    /**
     * Определяем лимит пиров для регулировки в зависимости от настроек для подраздела и торрент клиента.
     */
    public static function calcLimit(int $peerLimit, int $clientControlPeers, int $subsectionControlPeers): int
    {
        // Задан лимит для клиента и для раздела.
        if ($clientControlPeers > -1 && $subsectionControlPeers > -1) {
            // Если лимит для клиента меньше лимита для подраздела, то используем лимит для клиента.
            $peerLimit = $subsectionControlPeers;
            if ($clientControlPeers < $subsectionControlPeers) {
                $peerLimit = $clientControlPeers;
            }
        } elseif ($clientControlPeers > -1) {
            // Задан лимит только для клиента.
            $peerLimit = $clientControlPeers;
        } elseif ($subsectionControlPeers > -1) {
            // Задан лимит только для раздела.
            $peerLimit = $subsectionControlPeers;
        }

        return max($peerLimit, 0);
    }

    /**
     * Определить желаемое состояние раздачи в клиенте, в зависимости от текущих значений и настроек.
     */
    public static function determineDesiredState(TopicControl $controlConfig, TopicPeers $topic, int $peerLimit, bool $isSeeding): DesiredStatusChange
    {
        // Если у раздачи нет личей и выбрана опция "не сидировать без личей", то рандомно останавливаем раздачу.
        if ($isSeeding && self::shouldSkipSeeding(control: $controlConfig, topic: $topic)) {
            return DesiredStatusChange::RandomStop;
        }

        // Расчётное значение пиров раздачи.
        $peers = self::calculateTopicPeers(control: $controlConfig, topic: $topic, isSeeding: $isSeeding);

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
        if (abs($peers - $peerLimit) <= $controlConfig->randomApplyCount) {
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
    private static function calculateTopicPeers(TopicControl $control, TopicPeers $topic, bool $isSeeding): int
    {
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
                $keepers--;
            }

            // Вычитаем количество исключаемых хранителей.
            $peers -= min($keepers, $control->excludedKeepersCount);
        }

        return max(0, $peers);
    }

    /**
     * Определяет, следует ли остановить сидирование раздачи.
     */
    private static function shouldSkipSeeding(TopicControl $control, TopicPeers $topic): bool
    {
        return !$control->seedingWithoutLeechers && $topic->leechers === 0 && $topic->seeders > 1;
    }
}
