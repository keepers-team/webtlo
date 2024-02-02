<?php

require __DIR__ . '/vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\TIniFileEx;
use KeepersTeam\Webtlo\WebTLO;

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");

App::init();

$proxies = [
    null,
    ['gateway.keeps.cyou', 2081],
];

$forum = [
    'rutracker.org',
    'rutracker.net',
    'rutracker.nl',
];

$api = [
    'api.rutracker.cc',
];


function printProbe(array $forum, array $api, array $proxies): string
{
    $connectivity = [];
    checkAccess($connectivity, $proxies, $forum, "https://%s/forum/info.php?show=copyright_holders");
    checkAccess($connectivity, $proxies, $api, "https://%s/v1/get_client_ip");

    $output = "Domain           ";
    foreach ($proxies as $proxy) {
        $output .= " | " . str_pad(getNullSafeProxy($proxy), 18);
    }
    $output .= "\r\n";

    foreach (array_merge($forum, $api) as $url) {
        $output .= str_pad($url, 17);
        foreach ($proxies as $proxy) {
            $code  = $connectivity[$url][getNullSafeProxy($proxy)] ?? 0;
            $emoji = ($code < 300 && $code > 0) ? "✅" : "❌";

            $output .= " | " . str_pad($emoji . " " . $code, 18);
        }
        $output .= "\r\n";
    }

    $output .= "\r\n" . json_encode(getAbout(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\r\n";

    return $output;
}

function getAbout(): array
{
    $config = getConfig();
    $webtlo = WebTLO::getVersion();

    $parsed = [];

    $parsed['forum_url'] = $config['torrent-tracker']['forum_url'] == 'custom'
        ? $config['torrent-tracker']['forum_url_custom']
        : $config['torrent-tracker']['forum_url'];
    $parsed['forum_ssl'] = $config['torrent-tracker']['forum_ssl'];

    $parsed['api_url'] = $config['torrent-tracker']['api_url'] == 'custom'
        ? $config['torrent-tracker']['api_url_custom']
        : $config['torrent-tracker']['api_url'];
    $parsed['api_ssl'] = $config['torrent-tracker']['api_ssl'];


    if ($config['proxy']['activate_forum'] == 1 || $config['proxy']['activate_api'] == 1) {
        $parsed['proxy']['url']  = $config['proxy']['hostname'] . ":" . $config['proxy']['port'];
        $parsed['proxy']['type'] = $config['proxy']['type'];
    }
    $parsed['proxy']['activate_forum'] = $config['proxy']['activate_forum'];
    $parsed['proxy']['activate_api']   = $config['proxy']['activate_api'];


    return [
        'version' => $webtlo->version,
        'about'   => $webtlo->getAbout(),
        'config'  => $parsed,
    ];
}

function getConfig(): array
{
    $config_path = (new TIniFileEx())::getFile();
    if (!file_exists($config_path)) {
        return [];
    }

    return parse_ini_file($config_path, true);
}

function getNullSafeProxy(?array $proxy): string
{
    return $proxy ? '✅ '. $proxy[0] : "❎ no proxy";
}

function getUrl(string $url, ?array $proxy)
{
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_URL            => $url,
        ]);
        if (null !== $proxy) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            curl_setopt($ch, CURLOPT_PROXY, sprintf("%s:%d", $proxy[0], $proxy[1]));
        }
        curl_exec($ch);

        return curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } catch (Exception) {
        return null;
    }
}

function checkAccess(array &$connectivity, array $proxies, array $hostnames, string $tpl): void
{
    foreach ($hostnames as $hostname) {
        $url = sprintf($tpl, $hostname);
        foreach ($proxies as $proxy) {
            $connectivity[$hostname][getNullSafeProxy($proxy)] = getUrl($url, $proxy);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <title>webTLO configuration checker</title>
    <style>
        body {
            color: var(--text);
            background-color: var(--bg);
            font-size: 1.15rem;
            line-height: 1.5;
            display: grid;
            grid-template-columns: 1fr min(45rem, 90%) 1fr;
            margin: 0;
        }

        body > * {
            grid-column: 2;
        }

        h2 {
            font-size: 2rem;
            margin-top: 3rem;
            line-height: 1.1;
        }

        textarea {
            font-size: 1rem;
            padding: 1rem 1.4rem;
            max-width: 100%;
            overflow: auto;
            color: var(--preformatted);

            font-family: var(--mono-font);

            background-color: var(--accent-bg);
            border: 1px solid var(--border);
            border-radius: var(--standard-border-radius);
            margin-bottom: 1rem;
        }

        ::backdrop, :root {
            --sans-font: -apple-system, BlinkMacSystemFont, "Avenir Next", Avenir, "Nimbus Sans L", Roboto, "Noto Sans", "Segoe UI", Arial, Helvetica, "Helvetica Neue", sans-serif;
            --mono-font: Consolas, Menlo, Monaco, "Andale Mono", "Ubuntu Mono", monospace;

            --standard-border-radius: 5px;
            --bg: #212121;
            --accent-bg: #2b2b2b;
            --text: #dcdcdc;
            --text-light: #ababab;
            --accent: #ffb300;
            --code: #f06292;
            --preformatted: #ccc;
            --disabled: #111;
            --border: #898EA4;
        }
    </style>
</head>
<body>
<h2>webTLO configuration checker</h2>
<label>
<textarea rows="33" cols="120" spellcheck="false">
<?= printProbe($forum, $api, $proxies); ?>
</textarea>
</label>
</body>
</html>
