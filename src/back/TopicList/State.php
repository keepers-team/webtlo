<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class State
{
    public function __construct(
        public readonly string $icon,
        public readonly string $color,
        public readonly string $title
    ) {
    }

    public static function parseFromTorrent(array $topicData, int $daysRequire = 1, int $daysUpdate = 1): self
    {
        $icon  = self::getClientState($topicData);
        $color = self::getSeedColor($daysRequire, $daysUpdate);
        $title = self::getClientTitle($icon, $color);

        return new self($icon, $color, $title);
    }

    public static function clientOnly(array $topicData): self
    {
        $icon  = self::getClientState($topicData);
        $color = self::getClientColor($topicData);
        $title = self::getClientTitle($icon);

        return new self($icon, $color, $title);
    }

    public static function seedOnly(int $daysRequire = 14, int $daysUpdate = -1): self
    {
        $icon  = self::getClientState();
        $color = self::getSeedColor($daysRequire, $daysUpdate);
        $title = self::getClientTitle('', $color);

        return new self($icon, $color, $title);
    }

    /** Определить состояние раздачи в клиенте. */
    public static function getClientState(?array $topic = null): string
    {
        if (empty($topic)) {
            return 'fa-circle';
        }

        $topicDone = $topic['done'] ?? null;
        if (1 == $topicDone) {
            // Раздаётся.
            $topicState = 'fa-arrow-circle-o-up';
        } elseif (null === $topicDone) {
            // Нет в клиенте.
            $topicState = 'fa-circle';
        } else {
            // Скачивается.
            $topicState = 'fa-arrow-circle-o-down';
        }
        if (1 == $topic['paused'] ?? null) {
            // Приостановлена.
            $topicState = 'fa-pause-circle-o';
        }
        if (1 == $topic['error'] ?? null) {
            // С ошибкой в клиенте.
            $topicState = 'fa-times-circle-o';
        }

        return $topicState;
    }

    /** Определить цвет статуса раздачи в клиенте. */
    public static function getClientColor(?array $topic = null): string
    {
        $color = 'text-success';
        if (empty($topic) || $topic['done'] != 1 || $topic['error'] == 1) {
            $color = 'text-danger';
        }

        return $color;
    }

    /** Определить наличие информации о средних сидах в локальной БД. */
    private static function getSeedColor(int $daysRequire = 14, int $daysUpdate = -1): string
    {
        $color = 'text-info';

        // Количество дней, в которые набирались данные о средних сидах.
        if ($daysUpdate > 0) {
            $color = 'text-success';
            if ($daysUpdate < $daysRequire) {
                $color = ($daysUpdate >= $daysRequire / 2) ? 'text-warning' : 'text-danger';
            }
        }

        return $color;
    }

    /** Описание состояния раздачи в клиенте + наличие средних сидов. */
    public static function getClientTitle(string $state = '', string $color = ''): string
    {
        $topicStates = [
            'fa-circle'              => 'нет в клиенте',
            'fa-arrow-circle-o-up'   => 'раздаётся',
            'fa-arrow-circle-o-down' => 'скачивается',
            'fa-pause-circle-o'      => 'приостановлена',
            'fa-times-circle-o'      => 'с ошибкой в клиенте',
        ];

        $topicColors = [
            'text-success' => 'полные данные о средних сидах',
            'text-warning' => 'неполные данные о средних сидах',
            'text-danger'  => 'отсутствуют данные о средних сидах',
        ];

        $bulletTitle[] = $topicStates[$state] ?? null;
        $bulletTitle[] = $topicColors[$color] ?? null;

        $title = implode(', ', array_filter($bulletTitle));

        return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1, null);
    }
}