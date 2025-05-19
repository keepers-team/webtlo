<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\ApiReport\Actions;

use KeepersTeam\Webtlo\External\ApiReport\V1\KeeperTopics;

interface ReportProcessorInterface
{
    /**
     * @return iterable<KeeperTopics>
     */
    public function process(): iterable;
}
