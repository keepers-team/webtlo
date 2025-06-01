<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Опции поиска прочих и разрегистрированных раздач.
 */
final class TopicSearch
{
    public function __construct(
        public readonly bool $untracked,
        public readonly bool $unregistered,
    ) {}
}
