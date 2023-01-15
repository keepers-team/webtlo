<?php

namespace KeepersTeam\Webtlo\Config\V0;

final class Reports
{
    public function __construct(
        public readonly bool $autoClearMessages = false,
        public readonly bool $sendSummaryReport = true,
        /** @var int[] */
        public readonly array $excludeForumsIds = [],
    ) {
    }
}
