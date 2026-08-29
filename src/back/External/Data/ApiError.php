<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Data;

/** Ошибка при работе с Api. */
final class ApiError
{
    public function __construct(
        public readonly int    $code,
        public readonly string $text,
    ) {}

    public static function fromHttpCode(int $code): self
    {
        return new self(code: $code, text: 'Network error');
    }

    /**
     * @param ?array<string, mixed> $legacyError
     */
    public static function fromLegacyError(?array $legacyError): self
    {
        $error = $legacyError ?? [];

        return new self(
            code: $error['code'] ?? -1,
            text: $error['text'] ?? 'Unknown API error'
        );
    }

    public static function invalidMime(): self
    {
        return new self(code: -2, text: 'Invalid mime');
    }

    public static function malformedJson(): self
    {
        return new self(code: -3, text: 'Malformed JSON');
    }

    public static function emptyResponse(): self
    {
        return new self(code: -4, text: 'Empty JSON');
    }
}
