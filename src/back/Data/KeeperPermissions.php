<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Data;

/**
 * Статус хранителя, с точки зрения API и ограничения доступа, если есть.
 */
final class KeeperPermissions
{
    /**
     * @var list<int>
     */
    private array $skipped = [];

    /**
     * @param null|list<int> $allowedSubsections
     */
    public function __construct(
        public readonly bool   $isCurator,
        public readonly bool   $isCandidate,
        public readonly ?array $allowedSubsections = null,
    ) {}

    /**
     * Имеет ли кандидат доступ к заданному подразделу.
     */
    public function checkSubsectionAccess(int $forumId): bool
    {
        if ($this->allowedSubsections === null) {
            return true;
        }

        if (!in_array($forumId, $this->allowedSubsections, true)) {
            $this->skipped[] = $forumId;

            return false;
        }

        return true;
    }

    /**
     * @return list<int>
     */
    public function getSkippedSubsections(): array
    {
        $skipped = array_values(array_unique($this->skipped));
        sort($skipped);

        return $skipped;
    }
}
