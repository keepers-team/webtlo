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

    /** @var ?array{branch: string, commit: string, sha: string} */
    private static ?array $git = null;

    public function __construct(
        public readonly string $version,
        public readonly string $github,
        public readonly string $wiki,
        public readonly string $release,
        public readonly string $releaseApi,
        public readonly string $installation,
        public readonly string $sha,
    ) {}

    public static function getVersion(): self
    {
        return self::loadFromFile();
    }

    public static function loadFromFile(?string $file = null): self
    {
        if ($file === null) {
            $file = __DIR__ . '/../version.json';
        }

        // Пробуем считать версию из файла.
        if (file_exists($file)) {
            $result = json_decode((string) file_get_contents($file), true);
        }

        // Если ничего не нашлось, то пустой массив.
        $result ??= [];

        $result['version'] ??= 'git';

        // Если нет данных о версии, то пробуем их найти в Git.
        if (empty($result['sha'])) {
            $git = self::getGitInfo();
            if ($git !== null) {
                $result['version'] .= '-br-' . $git['branch'];
                $result['sha']     = $git['sha'];
            }
        }

        return new self(
            $result['version'],
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

        return sprintf(
            $pattern,
            htmlspecialchars($this->versionUrl(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->version, ENT_QUOTES, 'UTF-8')
        );
    }

    public function getCommitLink(): string
    {
        if (empty($this->sha)) {
            return '';
        }

        $pattern = /** @lang text */
            '<a class="version-sha" href="%s" target="_blank">#%s</a>';

        return sprintf(
            $pattern,
            htmlspecialchars($this->commitUrl(), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($this->sha, ENT_QUOTES, 'UTF-8')
        );
    }

    public function getWikiLink(): string
    {
        $pattern = /** @lang text */
            '<a href="%s" target="_blank">Web-TLO wiki</a>';

        return sprintf($pattern, htmlspecialchars($this->wiki, ENT_QUOTES, 'UTF-8'));
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
    public function getSoftwareInfo(): array
    {
        $about['webtlo_version'] = $this->version;

        $about['OS'] = PHP_OS;

        $server = $_SERVER['SERVER_SOFTWARE'] ?? '';
        if (!empty($server)) {
            $about['web_server'] = $server;
        }

        $about['installation']   = $this->installation;
        $about['git_sha']        = $this->sha;
        $about['php_version']    = phpversion();
        $about['sqlite_version'] = SQLite3::version()['versionString'];

        return $about;
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

        $about['memory_limit']       = (string) ini_get('memory_limit');
        $about['max_execution_time'] = (string) ini_get('max_execution_time');
        $about['max_input_time']     = (string) ini_get('max_input_time');
        $about['max_input_vars']     = (string) ini_get('max_input_vars');

        $about['date_timezone'] = ini_get('date.timezone') ?: date_default_timezone_get();

        return $about;
    }

    public function getInstallation(): string
    {
        $about = $this->getAbout();

        $result = [];
        foreach ($about as $key => $value) {
            $safeKey   = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
            $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

            $requirement = self::REQUIREMENTS[$key] ?? null;
            if (!empty($requirement)) {
                $isVersionValid = version_compare($value, $requirement, '>=');

                $safeValue = sprintf('<span class="%s">%s</span>', $isVersionValid ? 'text-success' : 'text-danger', $safeValue);
            }
            $result[] = sprintf('<li>%s: %s</li>', $safeKey, $safeValue);
        }

        return implode('', $result);
    }

    /**
     * Пробуем найти данные о версии в Git.
     *
     * https://github.com/Seldaek/monolog/blob/3.6.0/src/Monolog/Processor/GitProcessor.php#L66
     *
     * @return ?array{branch: string, commit: string, sha: string}
     */
    private static function getGitInfo(): ?array
    {
        if (self::$git !== null) {
            return self::$git;
        }

        $isCommandAvailable = static function(string $command): bool {
            $pattern = 'command -v %s 2> /dev/null';
            if ('WINNT' === PHP_OS) {
                $pattern = 'where %s 2> nul';
            }

            $exec = shell_exec(sprintf($pattern, $command));
            if (empty($exec)) {
                return false;
            }

            return is_executable(trim($exec));
        };

        if (!$isCommandAvailable('git')) {
            return null;
        }

        $branches = shell_exec('git branch -v --no-abbrev');
        if (is_string($branches) && preg_match('{^\* (.+?)\s+([a-f0-9]{40})(?:\s|$)}m', $branches, $matches) === 1) {
            return self::$git = [
                'branch' => $matches[1],
                'commit' => $matches[2],
                'sha'    => substr($matches[2], 0, 7),
            ];
        }

        return null;
    }
}
