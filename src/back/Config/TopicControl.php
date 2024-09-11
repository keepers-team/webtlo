<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Enum\ControlPeerLimitPriority as Priority;

/**
 * Параметры для регулировки (запуска/остановки) раздач в торрент-клиентах.
 */
final class TopicControl
{
    /**
     * Сид - пользователь, сидирующий раздачу.
     * Лич - пользователь, который либо качает раздачу, либо скачал только часть раздачи.
     * Сид-хранитель - это сид, из числа хранителей, который в данный момент сидирует раздачу.
     *
     * Сиды-хранители ⊆ Сиды
     */

    public const UnknownHashes = 'UnknownHashes';

    /**
     * @param int      $peersLimit             Предел пиров при регулировке
     * @param int      $excludedKeepersCount   Количество исключаемых из регулировки хранителей на раздаче
     * @param int      $randomApplyCount       Разница пиров, при которой применяется рандом переключения состояния раздачи
     * @param Priority $peerLimitPriority      Приоритет разных значений лимита пиров при регулировке
     * @param bool     $countLeechersAsPeers   Учитывать личей при подсчёте пиров
     * @param bool     $seedingWithoutLeechers Сидировать раздачи, на которых нет личей
     * @param bool     $manageOtherSubsections Регулировать раздачи из прочих подразделов
     * @param int      $daysUntilUnseeded      Количество дней, по прошествии которых раздача считается не сидируемой
     * @param int      $maxUnseededCount       Максимальное количество не сидируемых раздач, которые можно запустить одновременно
     */
    public function __construct(
        public readonly int      $peersLimit,
        public readonly int      $excludedKeepersCount,
        public readonly int      $randomApplyCount,
        public readonly Priority $peerLimitPriority,
        public readonly bool     $countLeechersAsPeers,
        public readonly bool     $seedingWithoutLeechers,
        public readonly bool     $manageOtherSubsections,
        public readonly int      $daysUntilUnseeded,
        public readonly int      $maxUnseededCount,
    ) {
    }

    /**
     * @param array<string, mixed> $cfg
     */
    public static function getTopicControl(array $cfg): TopicControl
    {
        $control = $cfg['topics_control'] ?? [];

        return new TopicControl(
            peersLimit            : (int)($control['peers'] ?? 10),
            excludedKeepersCount  : (int)($control['keepers'] ?? 3),
            randomApplyCount      : (int)($control['random'] ?? 1),
            peerLimitPriority     : Priority::from((int)($control['priority'] ?? 1)),
            countLeechersAsPeers  : (bool)($control['leechers'] ?? 0),
            seedingWithoutLeechers: (bool)($control['no_leechers'] ?? 1),
            manageOtherSubsections: (bool)($control['unadded_subsections'] ?? 0),
            daysUntilUnseeded     : (int)($control['days_until_unseeded'] ?? 21),
            maxUnseededCount      : (int)($control['max_unseeded_count'] ?? 100),
        );
    }
}
