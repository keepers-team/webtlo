<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Api\V1;

/** Статус раздачи. */
enum TorrentStatus: int
{
    case NotChecked    = 0;
    case Closed        = 1;
    case Checked       = 2;
    case Malformed     = 3;
    case NotFormed     = 4;
    case Duplicate     = 5;
    case Reserved      = 6;
    case Absorbed      = 7;
    case Doubtful      = 8;
    case Checking      = 9;
    case Temporary     = 10;
    case PreModeration = 11;

    /** @var TorrentStatus[] Валидные статусы раздач. */
    public const VALID = [
        self::NotChecked,
        self::Checked,
        self::Malformed,
        self::Doubtful,
        self::Temporary,
    ];

    public function label(): string
    {
        return match ($this) {
            self::NotChecked    => 'не проверено',
            self::Closed        => 'закрыто',
            self::Checked       => 'проверено',
            self::Malformed     => 'недооформлено',
            self::NotFormed     => 'не оформлено',
            self::Duplicate     => 'повтор',
            self::Reserved      => 'зарезервировано',
            self::Absorbed      => 'поглощено',
            self::Doubtful      => 'сомнительно',
            self::Checking      => 'проверяется',
            self::Temporary     => 'временная',
            self::PreModeration => 'премодерация',
        };
    }

    /**
     * Валидный ли статус раздачи.
     */
    public static function isValidStatus(self $status): bool
    {
        return in_array($status, self::VALID, true);
    }

    /**
     * Валидный ли статус раздачи.
     */
    public static function isValidStatusLabel(string $label): bool
    {
        $case = self::tryFromLabel($label);
        if (null === $case) {
            return false;
        }

        return self::isValidStatus($case);
    }

    /**
     * Пробуем по текстовому наименованию получить статус раздачи.
     */
    public static function tryFromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        return null;
    }
}
