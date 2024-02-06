<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\WebTLO;

$result = array_fill_keys(['newVersionNumber', 'newVersionLink', 'whatsNew'], '');

try {
    $wbtApi = WebTLO::getVersion();

    if (empty($wbtApi->releaseApi)) {
        throw new Exception('Невозможно проверить наличие новой версии.');
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $wbtApi->releaseApi,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_USERAGENT      => 'web-TLO',
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        Log::append('CURL ошибка: ' . curl_error($ch));
        throw new Exception('Невозможно связаться с api.github.com');
    }
    $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $infoFromGitHub = $responseHttpCode == 200 ? json_decode($response, true) : false;

    if (empty($infoFromGitHub)) {
        throw new Exception('Не удалось получить актуальную версию с GitHub');
    }

    $link = getReleaseLink($wbtApi->installation, $infoFromGitHub);

    $result['newVersionNumber'] = $infoFromGitHub['name'];
    $result['newVersionLink']   = $link;
    $result['whatsNew']         = $infoFromGitHub['body'];
} catch (Exception $e) {
    Log::append($e->getMessage());
}
$result['log'] = Log::get();

echo json_encode($result, JSON_UNESCAPED_UNICODE);

function getReleaseLink(string $install, array $github): string
{
    // По-умолчанию ссылка на релиз.
    $link = $github['html_url'];

    $assets = $github['assets'] ?? [];
    // Пробуем найти ссылку на конкретный zip.
    if (count($assets)) {
        if ('standalone' === $install) {
            foreach ($assets as $asset) {
                if (preg_match('/webtlo-win-.*\.zip/', $asset['name'])) {
                    return $asset['browser_download_url'];
                }
            }
        }
        if ('zip' === $install) {
            foreach ($assets as $asset) {
                if ('webtlo.zip' === $asset['name']) {
                    return $asset['browser_download_url'];
                }
            }
        }
    }

    return $link;
}
