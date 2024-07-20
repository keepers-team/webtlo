<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

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

    /**
     * @param int  $peersLimit             Предел пиров при регулировке
     * @param int  $excludedKeepersCount   Количество исключаемых из регулировки хранителей на раздаче
     * @param bool $countLeechersAsPeers   Учитывать личей при подсчёте пиров
     * @param bool $seedingWithoutLeechers Сидировать раздачи, на которых нет личей
     * @param bool $manageOtherSubsections Регулировать раздачи из прочих подразделов
     */
    public function __construct(
        public readonly int  $peersLimit,
        public readonly int  $excludedKeepersCount,
        public readonly bool $countLeechersAsPeers,
        public readonly bool $seedingWithoutLeechers,
        public readonly bool $manageOtherSubsections,
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
            countLeechersAsPeers  : (bool)($control['leechers'] ?? 0),
            seedingWithoutLeechers: (bool)($control['no_leechers'] ?? 1),
            manageOtherSubsections: (bool)($control['unadded_subsections'] ?? 0),
        );
    }
}
