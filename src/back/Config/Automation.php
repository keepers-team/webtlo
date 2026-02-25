<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

use KeepersTeam\Webtlo\Console\CronCommand;

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

    public function isCommandEnabled(CronCommand $command): bool
    {
        return match ($command) {
            CronCommand::Keepers,
            CronCommand::Update  => $this->update,
            CronCommand::Control => $this->control,
            CronCommand::Reports => $this->reports,
        };
    }
}
