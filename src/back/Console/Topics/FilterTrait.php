<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console\Topics;

use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\TopicList\ListingType;
use KeepersTeam\Webtlo\TopicList\ValidationException;
use RuntimeException;

trait FilterTrait
{
    /**
     * @param non-empty-string $filterName
     *
     * @return array<string, mixed>
     */
    protected function getFilterPreset(string $filterName): array
    {
        $presetsFile = Helper::getStorageDir() . '/filter_presets.json';

        // Чтение текущих пресетов.
        $presets = [];
        if (file_exists($presetsFile)) {
            $presets = json_decode((string) file_get_contents($presetsFile), true);
            if (!is_array($presets)) {
                $presets = [];
            }
        }

        $filter = [];
        if (isset($presets[$filterName])) {
            $filter = $presets[$filterName];
        }

        if (empty($filter) || !is_array($filter)) {
            $this->logger->notice('Пресет фильтра не найден.', ['filterName' => $filterName]);

            throw new RuntimeException("Пресет фильтра [$filterName] не найден");
        }

        $this->logger->debug('Пресет фильтра найден.', ['filterName' => $filterName, 'filter' => $filter]);

        return $filter;
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return string[]
     */
    protected function getTopicsHashes(int $listingId, array $filter): array
    {
        try {
            $result = $this->listing->findTopics(listingId: $listingId, filter: $filter);
        } catch (ValidationException $e) {
            $this->logger->warning(
                'Ошибка валидации фильтра',
                ['error' => $e->getMessage(), 'class' => $e->getClass()]
            );

            throw new RuntimeException('Ошибка валидации фильтра.');
        }

        if (!count($result->groups)) {
            throw new RuntimeException('Раздачи не найдены.');
        }

        $hashes      = [];
        $listingType = ListingType::tryFallBack($listingId);
        foreach ($result->groups as $group) {
            foreach ($group->topics as $topic) {
                if ($listingType === ListingType::Unregistered) {
                    $updatedHash = $topic->details['updated_hash'] ?? null;
                    if (!is_string($updatedHash) || $updatedHash === '') {
                        continue;
                    }

                    $hashes[] = $updatedHash;

                    continue;
                }

                $hashes[] = $topic->topic->hash;
            }
        }

        return array_values(array_unique($hashes));
    }
}
