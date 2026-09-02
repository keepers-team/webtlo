<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\TopicList\TopicGroup;

trait NatSortTrait
{
    /**
     * @param TopicGroup[] $groups
     *
     * @return TopicGroup[]
     */
    protected static function sortGroups(array $groups): array
    {
        uasort($groups, static function(TopicGroup $a, TopicGroup $b) {
            if ($a->title === null || $b->title === null) {
                return $a->key <=> $b->key;
            }

            return strnatcasecmp(
                Helper::prepareCompareString($a->title),
                Helper::prepareCompareString($b->title),
            );
        });

        return $groups;
    }
}
