<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Contract;

use KeepersTeam\Webtlo\External\ApiReport\V1\KeeperTopics;

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
