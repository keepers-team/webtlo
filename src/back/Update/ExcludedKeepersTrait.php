<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Update;

trait ExcludedKeepersTrait
{
    /** @var int[] */
    protected array $excludedKeepers = [];

    /**
     * @param int[] $excluded
     */
    public function setExcludedKeepers(array $excluded): void
    {
        $this->excludedKeepers = $excluded;
    }

    /**
     * @param array<string, mixed>[] $config
     * @return int[]
     */
    public static function getExcludedKeepersList(array $config): array
    {
        $excludedKeepers = preg_replace('/[^0-9]/', '|', $config['reports']['exclude_keepers_ids'] ?? '');
        $excludedKeepers = explode('|', $excludedKeepers);

        return array_map(fn($el) => (int) $el, array_filter($excludedKeepers));
    }
}
