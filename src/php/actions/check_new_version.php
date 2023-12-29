<?php

use KeepersTeam\Webtlo\WebTLO;

$result = array_fill_keys(['newVersionNumber', 'newVersionLink', 'whatsNew'], '');
try {
    include_once dirname(__FILE__) . '/../common.php';

    $wbtApi = WebTLO::getVersion();

    if (empty($wbtApi->releaseApi)) {
        throw new Exception('Невозможно проверить наличие новой версии.');
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $wbtApi->releaseApi,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'web-TLO'
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
        throw new Exception('Что-то пошло не так при попытке получить актуальную версию с GitHub');
    }

    $result['newVersionNumber'] = $infoFromGitHub['name'];
    $result['newVersionLink']   = $infoFromGitHub['zipball_url'];
    $result['whatsNew']         = $infoFromGitHub['body'];
} catch (Exception $e) {
    Log::append($e->getMessage());
}
$result['log'] = Log::get();

echo json_encode($result, JSON_UNESCAPED_UNICODE);