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

    /**
     * @param literal-string $name
     */
    public function integerArgument(string $name): int
    {
        $value = filter_var(
            $this->argument($name),
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE,
        );
        if (!is_int($value)) {
            throw new RuntimeException(
                sprintf('Invalid integer argument: %s', $name)
            );
        }

        return $value;
    }

    /**
     * @param literal-string $name
     */
    public function booleanArgument(string $name): bool
    {
        $value = filter_var(
            $this->argument($name),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );
        if (!is_bool($value)) {
            throw new RuntimeException(
                sprintf('Invalid boolean argument: %s', $name)
            );
        }

        return $value;
    }
}
