<?php

try {
    include_once dirname(__FILE__) . '/../common.php';

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://api.github.com/repos/keepers-team/webtlo/releases/latest',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 40,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'web-TLO'
    ));
    $response = curl_exec($ch);
    if ($response === false) {
        Log::append('CURL ошибка: ' . curl_error($ch));
        Log::append('Невозможно связаться с api.github.com');
        return false;
    }
    $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $infoFromGitHub = $responseHttpCode == 200 ? json_decode($response, true) : false;

    if (empty($infoFromGitHub)) {
        throw new Exception('Что-то пошло не так при попытке получить актуальную версию с GitHub');
    }

    echo json_encode(
        array(
            'log' => '',
            'newVersionNumber' => $infoFromGitHub['name'],
            'newVersionLink' => $infoFromGitHub['zipball_url'],
            'whatsNew' => $infoFromGitHub['body'],
        )
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo json_encode(
        array(
            'log' => Log::get(),
            'newVersionNumber' => '',
            'newVersionLink' => '',
            'whatsNew' => '',
        )
    );
}
