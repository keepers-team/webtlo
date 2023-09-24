<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\DTO;

final class KeysObject
{
    public function __construct(public string $keys, public array $values)
    {
    }

    public static function create(array $data): self
    {
        $values = count($data) ? $data : [''];
        $keys   = str_repeat('?,', count($values) - 1) . '?';

        return new self($keys, $values);
    }
}