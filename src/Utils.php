<?php

namespace KeepersTeam\Webtlo;

final class Utils
{
    public static function getVersion(): object
    {
        $webtlo_version_defaults = [
            'version' => '',
            'github' => '',
            'wiki' => '',
            'release' => '',
            'release_api' => '',
            'version_url' => '',
            'version_line' => 'Версия TLO: [b]Web-TLO-unknown[/b]',
            'version_line_url' => "Версия TLO: [b]Web-TLO-[url='#']unknown[/url][/b]"
        ];
        $version_json_path = __DIR__ . DIRECTORY_SEPARATOR . 'version.json';

        if (!file_exists($version_json_path)) {
            error_log('`version.json` not found! Make sure you copied all files from the repo.');
            return (object)$webtlo_version_defaults;
        }
        $version_json = json_decode(file_get_contents($version_json_path), true);
        $result = (object)array_merge($webtlo_version_defaults, $version_json);

        if (!empty($result->version)) {
            $result->version_url = $result->github . '/releases/tag/' . $result->version;
            $result->version_line = 'Версия TLO: [b]Web-TLO-' . $result->version . '[/b]';
            $result->version_line_url = 'Версия TLO: [b]Web-TLO-[url=' . $result->version_url . ']' . $result->version . '[/url][/b]';
        }
        return $result;
    }


    public static function rmdir_recursive($path): bool
    {
        $return = true;
        if (!file_exists($path)) {
            return true;
        }
        if (!is_dir($path)) {
            return unlink($path);
        }
        foreach (scandir($path) as $next_path) {
            if ('.' === $next_path || '..' === $next_path) {
                continue;
            }
            if (is_dir("$path/$next_path")) {
                if (!is_writable("$path/$next_path")) {
                    return false;
                }
                $return = self::rmdir_recursive("$path/$next_path");
            } else {
                unlink("$path/$next_path");
            }
        }
        return $return && is_writable($path) && rmdir($path);
    }

    public static function natsort_field(array $input, $field, $direct = 1): array
    {
        uasort($input, function ($a, $b) use ($field, $direct) {
            if (
                is_numeric($a[$field])
                && is_numeric($b[$field])
            ) {
                return ($a[$field] != $b[$field] ? $a[$field] < $b[$field] ? -1 : 1 : 0) * $direct;
            }
            $a[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($a[$field], 'UTF-8'));
            $b[$field] = mb_ereg_replace('ё', 'е', mb_strtolower($b[$field], 'UTF-8'));
            return (strnatcasecmp($a[$field], $b[$field])) * $direct;
        });
        return $input;
    }


    public static function convert_bytes(int $size): string
    {
        $filesizename = [" B", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB"];
        $i = $size >= pow(1024, 4) ? 3 : floor(log($size, 1024));
        return $size ? round($size / pow(1024, $i), 2) . $filesizename[$i] : '0 B';
    }

    public static function convert_seconds(int $seconds, bool $leadzeros = false): string
    {
        $hours = floor($seconds / 3600);
        $mins = floor($seconds / 60 % 60);
        $secs = $seconds % 60;
        if ($leadzeros) {
            if (strlen($hours) == 1) {
                $hours = "0" . $hours;
            }
            if (strlen($secs) == 1) {
                $secs = "0" . $secs;
            }
            if (strlen($mins) == 1) {
                $mins = "0" . $mins;
            }
        }
        if ($hours == 0) {
            if ($mins == 0) {
                $ret = "${secs}s";
            } else {
                $ret = "${mins}m ${secs}s";
            }
        } else {
            $ret = "${hours}h ${mins}m ${secs}s";
        }
        return $ret;
    }
}
