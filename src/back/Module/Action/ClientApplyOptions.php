<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module\Action;

use KeepersTeam\Webtlo\TopicList\ListingType;

final class ClientApplyOptions
{
    public function __construct(
        public readonly ?string      $label,
        public readonly bool         $forceStart,
        public readonly bool         $removeFiles,
        public readonly ?ListingType $listingType,
    ) {}
}
