<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

final class WebTLO
{
    public function __construct(
        public readonly string $version,
        public readonly string $github,
        public readonly string $wiki,
        public readonly string $release,
        public readonly string $releaseApi,
    ) {
    }

    public static function getVersion(): self
    {
        return self::loadFromFile();
    }

    public static function loadFromFile(?string $file = null): self
    {
        if (null === $file) {
            $file = __DIR__ . '/../version.json';
        }

        if (file_exists($file)) {
            $result = json_decode(file_get_contents($file), true);
        }

        return new self(
            $result['version'] ?? 'unknown',
            $result['github'] ?? '',
            $result['wiki'] ?? '#',
            $result['release'] ?? '',
            $result['release_api'] ?? '',
        );
    }

    public function versionUrl(): string
    {
        if (!empty($this->github)) {
            return sprintf('%s/releases/tag/%s', $this->github, $this->version);
        }

        return '#';
    }

    public function versionLine(): string
    {
        return sprintf('Версия TLO: [b]Web-TLO-%s[/b]', $this->version);
    }

    public function versionLineUrl(): string
    {
        return sprintf('Версия TLO: [b]Web-TLO-[url=%s]%s[/url][/b]', $this->versionUrl(), $this->version);
    }

    public function wikiUrl(): string
    {
        return $this->wiki;
    }
}