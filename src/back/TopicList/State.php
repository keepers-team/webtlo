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

    public function getIconElem(string $classes = ''): string
    {
        $classes = ['fa', 'fa-size', "fa-$this->icon", "text-$this->color", $classes];
        $classes = implode(' ', array_filter($classes));

        return sprintf("<i class='%s' title='%s'></i>", $classes, $this->title);
    }

    public function getStringElem(string $text, string $classes = ''): string
    {
        $classes = ["text-$this->color", $classes];
        $classes = implode(' ', array_filter($classes));

        return sprintf("<i class='%s' title='%s'>%s</i>", $classes, $this->title, $text);
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
            return 'circle';
        }

        $topicDone = $topic['done'] ?? null;
        if (1 == $topicDone) {
            // Раздаётся.
            $topicState = 'arrow-circle-o-up';
        } elseif (null === $topicDone) {
            // Нет в клиенте.
            $topicState = 'circle';
        } else {
            // Скачивается.
            $topicState = 'arrow-circle-o-down';
        }
        if (1 === (int)($topic['paused'] ?? 0)) {
            // Приостановлена.
            $topicState = 'pause-circle-o';
        }
        if (1 === (int)($topic['error'] ?? 0)) {
            // С ошибкой в клиенте.
            $topicState = 'times-circle-o';
        }

        return $topicState;
    }

    /** Определить цвет статуса раздачи в клиенте. */
    public static function getClientColor(?array $topic = null): string
    {
        $color = 'success';
        if (empty($topic) || $topic['done'] != 1 || $topic['error'] == 1) {
            $color = 'danger';
        }

        return $color;
    }

    /** Определить наличие информации о средних сидах в локальной БД. */
    private static function getSeedColor(int $daysRequire = 14, int $daysUpdate = -1): string
    {
        $color = 'info';

        // Количество дней, в которые набирались данные о средних сидах.
        if ($daysUpdate > -1) {
            $color = 'success';
            if ($daysUpdate < $daysRequire) {
                $color = ($daysUpdate >= $daysRequire / 2) ? 'warning' : 'danger';
            }
        }

        return $color;
    }

    /** Описание состояния раздачи в клиенте + наличие средних сидов. */
    public static function getClientTitle(string $state = '', string $color = ''): string
    {
        $topicStates = [
            'circle'              => 'нет в клиенте',
            'arrow-circle-o-up'   => 'раздаётся',
            'arrow-circle-o-down' => 'скачивается',
            'pause-circle-o'      => 'приостановлена',
            'times-circle-o'      => 'с ошибкой в клиенте',
        ];

        $topicColors = [
            'success' => 'полные данные о средних сидах',
            'warning' => 'неполные данные о средних сидах',
            'danger'  => 'отсутствуют данные о средних сидах',
        ];

        $bulletTitle[] = $topicStates[$state] ?? null;
        $bulletTitle[] = $topicColors[$color] ?? null;

        $title = implode(', ', array_filter($bulletTitle));

        return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1, null);
    }
}
