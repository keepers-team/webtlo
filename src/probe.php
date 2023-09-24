<?php

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");

$proxies = [
    null,
    ['gateway.keeps.cyou', 2081]
];

$forum = [
    'rutracker.org',
    'rutracker.net',
    'rutracker.nl'
];

$api = [
    'api.rutracker.cc'
];

$connectivity = array();
foreach ($forum as $addr){
    $connectivity[$addr] = array();
}

function getWebTloVersion(){
    $version_json_path = dirname(__FILE__) . '/version.json';
    if (!file_exists($version_json_path)) {
        return "version file not found";
    }
    $version_json = (object) json_decode(file_get_contents($version_json_path), true);
    return $version_json->version;
}

function getConfig(){
    $config_path = dirname(__FILE__) . '/data/config.ini';
    if (!file_exists($config_path)) {
        return array();
    }
    $config = parse_ini_file($config_path, true);
    return $config;
}

function getNullSafeProxy($proxy) {
    return $proxy ? $proxy[0] : "no proxy";
}

function getUrl($url, $proxy) {
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36",
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_URL => $url,
        ]);
        if (null !== $proxy) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            curl_setopt($ch, CURLOPT_PROXY, sprintf("%s:%d", $proxy[0], $proxy[1]));
        }
        curl_exec($ch);
        return curl_getinfo($ch, CURLINFO_HTTP_CODE);
    } catch (Exception $e) {
        return null;
    }
}

function checkAccess($proxies, $hostnames, $tpl) {
    foreach ($hostnames as $hostname) {
        $url = sprintf($tpl, $hostname);
        foreach ($proxies as $proxy) {
            $GLOBALS['connectivity'][$hostname][getNullSafeProxy($proxy)] = getUrl($url, $proxy);
        }
    }
}

checkAccess($proxies, $forum, "https://%s/forum/info.php?show=copyright_holders");
checkAccess($proxies, $api, "https://%s/v1/get_client_ip");

$config = getConfig();

$probe = new stdClass();

$probe->version = getWebTloVersion();

$probe->config = new stdClass();
$probe->config->forum_url = $config['torrent-tracker']['forum_url'] == 'custom' ? $config['torrent-tracker']['forum_url_custom'] : $config['torrent-tracker']['forum_url'];
$probe->config->forum_ssl = $config['torrent-tracker']['forum_ssl'];
$probe->config->api_url = $config['torrent-tracker']['api_url'] == 'custom' ? $config['torrent-tracker']['api_url_custom'] : $config['torrent-tracker']['api_url'];
$probe->config->api_ssl = $config['torrent-tracker']['api_ssl'];

$probe->config->proxy = new stdClass();
if($config['proxy']['activate_forum'] == 1 || $config['proxy']['activate_api'] == 1){
    $probe->config->proxy->url = $config['proxy']['hostname'].":".$config['proxy']['port'];
    $probe->config->proxy->type = $config['proxy']['type'];
}
$probe->config->proxy->activate_forum = $config['proxy']['activate_forum'];
$probe->config->proxy->activate_api = $config['proxy']['activate_api'];

$probe->server = $_SERVER['SERVER_SOFTWARE'];

$probe->php = new stdClass();
$probe->php->version = phpversion();
$probe->php->memory_limit = ini_get('memory_limit');
$probe->php->max_execution_time = ini_get('max_execution_time');
$probe->php->max_input_time = ini_get('max_input_time');
$probe->php->max_input_vars = ini_get('max_input_vars');
?>
<!DOCTYPE html>
<html>
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
            grid-template-columns: 1fr min(45rem,90%) 1fr;
            margin: 0;
        }

        body>* {
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
            --sans-font: -apple-system,BlinkMacSystemFont,"Avenir Next",Avenir,"Nimbus Sans L",Roboto,"Noto Sans","Segoe UI",Arial,Helvetica,"Helvetica Neue",sans-serif;
            --mono-font: Consolas,Menlo,Monaco,"Andale Mono","Ubuntu Mono",monospace;

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
<textarea rows="30" cols="120" spellcheck="false">
<?php

$line1 = "Domain           ";
foreach ($proxies as $proxy){
    $line1 = $line1." | ".str_pad(getNullSafeProxy($proxy), 18);
}
echo $line1."\r\n";

foreach (array_merge($forum, $api) as $url){
    $line = str_pad($url, 17);
    foreach ($proxies as $proxy){
        $code = $connectivity[$url][getNullSafeProxy($proxy)];
        $emoji = ($code < 300 && $code > 0) ? "✅" : "❌";
        $line = $line." | ".str_pad($emoji." ".$code, 18);
    }
    echo $line."\r\n";
}

echo "\r\n".json_encode($probe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\r\n";
?>
</textarea>
</body>
</html>
