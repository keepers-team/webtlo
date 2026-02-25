<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use KeepersTeam\Webtlo\Action\TopicControl;

/**
 * Запуск регулировки раздач в торрент-клиентах.
 *
 * На возможность выполнения влияет опция "Автоматизация и дополнительные настройки" > "[control.php]".
 */
final class CronControl
{
    public function __construct(private readonly TopicControl $control) {}

    public function run(): void
    {
        $this->control->process();
    }
}
