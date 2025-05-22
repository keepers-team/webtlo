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
     * @param int      $peersLimit             предел пиров при регулировке
     * @param string   $peersLimitIntervals    набор интервалов предела пиров
     * @param int      $excludedKeepersCount   количество исключаемых из регулировки хранителей на раздаче
     * @param int      $randomApplyCount       разница пиров, при которой применяется рандом переключения состояния раздачи
     * @param Priority $peerLimitPriority      приоритет разных значений лимита пиров при регулировке
     * @param bool     $countLeechersAsPeers   учитывать личей при подсчёте пиров
     * @param bool     $seedingWithoutLeechers сидировать раздачи, на которых нет личей
     * @param bool     $manageOtherSubsections регулировать раздачи из прочих подразделов
     * @param int      $daysUntilUnseeded      количество дней, по прошествии которых раздача считается не сидируемой
     * @param int      $maxUnseededCount       максимальное количество не сидируемых раздач, которые можно запустить одновременно
     */
    public function __construct(
        public readonly int      $peersLimit,
        public readonly string   $peersLimitIntervals,
        public readonly int      $excludedKeepersCount,
        public readonly int      $randomApplyCount,
        public readonly Priority $peerLimitPriority,
        public readonly bool     $countLeechersAsPeers,
        public readonly bool     $seedingWithoutLeechers,
        public readonly bool     $manageOtherSubsections,
        public readonly int      $daysUntilUnseeded,
        public readonly int      $maxUnseededCount,
    ) {}
}
