<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Enum;

enum DesiredStatusChange
{
    case Nothing;
    case Start;
    case Stop;
    case RandomStart;
    case RandomStop;

    public function shouldStartSeeding(): bool
    {
        return match ($this) {
            self::Start       => true,
            self::RandomStart => (bool)rand(0, 1),
            default           => false,
        };
    }

    public function shouldStopSeeding(): bool
    {
        return match ($this) {
            self::Stop       => true,
            self::RandomStop => (bool)rand(0, 1),
            default          => false,
        };
    }

    public function isRandom(): bool
    {
        return match ($this) {
            self::RandomStop,
            self::RandomStart => true,
            default           => false,
        };
    }

    public function toEmoji(): string
    {
        return match ($this) {
            self::Nothing     => '💠',
            self::Start       => '✅',
            self::Stop        => '❌',
            self::RandomStart => '🎲✅',
            self::RandomStop  => '🎲❌',
        };
    }
}
