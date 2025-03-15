<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

final class State
{
    public function __construct(
        public readonly StateClientIcon|StateKeeperIcon $icon,
        public readonly StateColor                      $color,
        public readonly string                          $title
    ) {}

    public function getIconElem(string $classes = ''): string
    {
        $classes = [
            'fa',
            'fa-size',
            "fa-{$this->icon->value}",
            "text-{$this->color->value}",
            $classes,
        ];
        $classes = implode(' ', array_filter($classes));

        return sprintf("<i class='%s' title='%s'></i>", $classes, $this->title);
    }

    public function getStringElem(string $text, string $classes = ''): string
    {
        $classes = ["text-{$this->color->value}", $classes];
        $classes = implode(' ', array_filter($classes));

        return sprintf("<i class='%s' title='%s'>%s</i>", $classes, $this->title, $text);
    }

    /**
     * @param array<string, mixed> $topicData
     */
    public static function parseFromTorrent(array $topicData, int $daysRequire = 1, int $daysUpdate = 1): self
    {
        $icon  = self::getClientState($topicData);
        $color = self::getSeedColor($daysRequire, $daysUpdate);
        $title = self::getClientTitle(
            state: $icon,
            color: $color,
            error: self::checkErrorMessage($topicData),
        );

        return new self($icon, $color, $title);
    }

    /**
     * @param array<string, mixed> $topicData
     */
    public static function clientOnly(array $topicData): self
    {
        $icon  = self::getClientState($topicData);
        $color = self::getClientColor($topicData);
        $title = self::getClientTitle(
            state: $icon,
            error: self::checkErrorMessage($topicData),
        );

        return new self($icon, $color, $title);
    }

    public static function seedOnly(int $daysRequire = 14, int $daysUpdate = -1): self
    {
        $icon  = self::getClientState();
        $color = self::getSeedColor($daysRequire, $daysUpdate);
        $title = self::getClientTitle(color: $color);

        return new self($icon, $color, $title);
    }

    /**
     * Определить состояние раздачи в клиенте.
     *
     * @param ?array<string, mixed> $topic
     */
    public static function getClientState(?array $topic = null): StateClientIcon
    {
        if (empty($topic)) {
            return StateClientIcon::NotAdded;
        }

        $topicDone = $topic['done'] ?? null;
        if ($topicDone === null) {
            // Нет в клиенте.
            $topicState = StateClientIcon::NotAdded;
        } elseif ($topicDone == 1) {
            // Раздаётся.
            $topicState = StateClientIcon::Seeding;
        } else {
            // Скачивается.
            $topicState = StateClientIcon::Downloading;
        }

        if ((int) ($topic['paused'] ?? 0) === 1) {
            // Приостановлена.
            $topicState = StateClientIcon::Paused;
        }

        if ((int) ($topic['error'] ?? 0) === 1) {
            // С ошибкой в клиенте.
            $topicState = StateClientIcon::Error;
        }

        return $topicState;
    }

    /**
     * Определить цвет статуса раздачи в клиенте.
     *
     * @param ?array<string, mixed> $topic
     */
    public static function getClientColor(?array $topic = null): StateColor
    {
        $color = StateColor::Success;
        if (empty($topic) || $topic['done'] != 1 || $topic['error'] == 1) {
            $color = StateColor::Danger;
        }

        return $color;
    }

    /** Определить наличие информации о средних сидах в локальной БД. */
    private static function getSeedColor(int $daysRequire = 14, int $daysUpdate = -1): StateColor
    {
        $color = StateColor::Info;

        // Количество дней, в которые набирались данные о средних сидах.
        if ($daysUpdate > -1) {
            $color = StateColor::Success;
            if ($daysUpdate < $daysRequire) {
                $color = ($daysUpdate >= $daysRequire / 2) ? StateColor::Warning : StateColor::Danger;
            }
        }

        return $color;
    }

    /** Описание состояния раздачи в клиенте + наличие средних сидов. */
    public static function getClientTitle(
        ?StateClientIcon $state = null,
        ?StateColor      $color = null,
        ?string          $error = null
    ): string {
        $bulletTitles = [];

        if ($state !== null) {
            $bulletTitles[] = $state->label();
        }

        if ($color !== null) {
            $bulletTitles[] = $color->label();
        }

        if ($error !== null) {
            $bulletTitles[] = "[$error]";
        }

        if (!count($bulletTitles)) {
            return '';
        }

        $title = implode(', ', array_filter($bulletTitles));

        return mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1, null);
    }

    /**
     * @param array<string, mixed> $topic
     */
    public static function checkErrorMessage(array $topic): ?string
    {
        if (!empty($topic['error_message'])) {
            return (string) $topic['error_message'];
        }

        return null;
    }
}
