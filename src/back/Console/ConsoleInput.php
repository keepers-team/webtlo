<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Console;

use RuntimeException;

final class ConsoleInput
{
    /**
     * @param array<string, string> $arguments
     */
    public function __construct(
        public readonly array $arguments = [],
    ) {}

    /**
     * @param literal-string $name
     *
     * @return non-empty-string
     */
    public function argument(string $name): string
    {
        if (!isset($this->arguments[$name]) || $this->arguments[$name] === '') {
            throw new RuntimeException(
                sprintf('Missing required argument: %s', $name)
            );
        }

        return $this->arguments[$name];
    }
}
