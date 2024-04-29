<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use SQLite3;

final class WebTLO
{
    private const AppName = 'Web-TLO';

    private const REQUIREMENTS = [
        'php_version'    => '8.1.0',
        'sqlite_version' => '3.38.0',
    ];

    public function __construct(
        public readonly string $version,
        public readonly string $github,
        public readonly string $wiki,
        public readonly string $release,
        public readonly string $releaseApi,
        public readonly string $installation,
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
            $result['installation'] ?? 'git',
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
        return sprintf('Версия TLO: [b]%s-%s[/b]', self::AppName, $this->version);
    }

    public function versionLineUrl(): string
    {
        return sprintf(
            'Версия TLO: [b]%s-[url=%s]%s[/url][/b]',
            self::AppName,
            $this->versionUrl(),
            $this->version
        );
    }

    public function getReleaseLink(): string
    {
        $pattern = /** @lang text */
            'Web-TLO <a href="%s" target="_blank">%s</a>';

        return sprintf($pattern, $this->versionUrl(), $this->version);
    }

    public function getCommitLink(): string
    {
        if (empty($this->sha)) {
            return '';
        }

        $pattern = /** @lang text */
            '<a class="version-sha" href="%s" target="_blank">#%s</a>';

        return sprintf($pattern, $this->commitUrl(), $this->sha);
    }

    public function getWikiLink(): string
    {
        $pattern = /** @lang text */
            '<a href="%s" target="_blank">Web-TLO wiki</a>';

        return sprintf($pattern, $this->wiki);
    }

    public function appVersionLine(): string
    {
        $info = [
            self::AppName,
            $this->version,
            "[$this->installation]",
            $this->sha ? '#' . $this->sha : '',
        ];

        return implode(' ', array_filter($info));
    }

    /**
     * @return array<string, string>
     */
    public function getAbout(): array
    {
        $system = array_filter([
            $this->installation,
            $_SERVER['SERVER_SOFTWARE'] ?? '',
        ]);

        $about['OS']     = PHP_OS;
        $about['system'] = implode(' + ', $system);

        $about['php_version']    = phpversion();
        $about['sqlite_version'] = SQLite3::version()['versionString'];

        $about['memory_limit']       = ini_get('memory_limit');
        $about['max_execution_time'] = ini_get('max_execution_time');
        $about['max_input_time']     = ini_get('max_input_time');
        $about['max_input_vars']     = ini_get('max_input_vars');

        $about['date_timezone'] = ini_get('date.timezone') ?: date_default_timezone_get();

        return $about;
    }

    public function getInstallation(): string
    {
        $about = $this->getAbout();

        $result = [];
        foreach ($about as $key => $value) {
            $requirement = self::REQUIREMENTS[$key] ?? null;
            if (!empty($requirement)) {
                $isVersionValid = version_compare($value, $requirement, '>=');

                $value = sprintf('<span class="%s">%s<span>', $isVersionValid ? 'text-success' : 'text-danger', $value);
            }
            $result[] = sprintf('<li>%s: %s</li>', $key, $value);
        }

        return implode('', $result);
    }
}
