<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Параметры автоматического запуска задач по-расписанию.
 */
final class Automation
{
    public function __construct(
        public readonly bool $update,
        public readonly bool $control,
        public readonly bool $reports,
    ) {}

    public function isActionEnabled(string $action): bool
    {
        return match ($action) {
            'update'  => $this->update,
            'control' => $this->control,
            'reports' => $this->reports,
            default   => false
        };
    }
}
