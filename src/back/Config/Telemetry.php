<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Config;

/**
 * Телеметрия - публичные данные об используемом ПО.
 */
final class Telemetry
{
    /**
     * @param array{}|array<string, mixed> $info
     */
    public function __construct(
        public readonly array $info
    ) {}
}
