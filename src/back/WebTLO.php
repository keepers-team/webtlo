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
        public readonly string $sha,
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
            $result['sha'] ?? '',
        );
    }

    public function versionUrl(): string
    {
        if (!empty($this->github)) {
            // Если версия принадлежит какой-то ветке, отправляем на коммит.
            if (str_contains($this->version, '-br-')) {
                return $this->commitUrl();
            }

            return sprintf('%s/releases/tag/%s', $this->github, $this->version);
        }

        return '#';
    }

    public function commitUrl(): string
    {
        if (!empty($this->github)) {
            return sprintf('%s/commit/%s', $this->github, $this->sha);
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

    public function getReleaseLink(): string
    {
        return sprintf('Web-TLO <a href="%s" target="_blank">%s</a>', $this->versionUrl(), $this->version);
    }

    public function getCommitLink(): string
    {
        if (empty($this->sha)) {
            return '';
        }

        return sprintf('<a class="version-sha" href="%s" target="_blank">#%s</a>', $this->commitUrl(), $this->sha);
    }

    public function getWikiLink(): string
    {
        return sprintf('<a href="%s" target="_blank">Web-TLO wiki</a>', $this->wiki);
    }
}