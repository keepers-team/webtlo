<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Legacy;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Хранение журнала в памяти приложения.
 */
final class MemoryLoggerHandler extends AbstractProcessingHandler implements HandlerInterface
{
    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        Log::append(trim((string) $record->formatted));
    }
}
