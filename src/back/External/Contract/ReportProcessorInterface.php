<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Contract;

use KeepersTeam\Webtlo\External\Data\KeeperTopics;

/**
 * Интерфейс для ленивого перебора отчётов хранителей (списка хранимых раздач).
 *
 * @see KeeperTopics
 */
interface ReportProcessorInterface
{
    /**
     * @return iterable<KeeperTopics>
     */
    public function process(): iterable;
}
