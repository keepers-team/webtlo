<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\DTO;

final class KeysObject
{
    /**
     * @param int[]|string[] $values
     */
    public function __construct(public string $keys, public array $values) {}

    /**
     * @param array<int|string, int|string> $data
     */
    public static function create(array $data): self
    {
        $values = count($data) ? array_values($data) : [''];
        $keys   = str_repeat('?,', count($values) - 1) . '?';

        return new self($keys, $values);
    }
}
