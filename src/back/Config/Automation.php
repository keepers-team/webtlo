<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Console\ConsoleCommand;

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

    public function isCommandEnabled(ConsoleCommand $command): bool
    {
        return match ($command) {
            ConsoleCommand::Keepers,
            ConsoleCommand::Update  => $this->update,
            ConsoleCommand::Control => $this->control,
            ConsoleCommand::Reports => $this->reports,
            default                 => true,
        };
    }
}
