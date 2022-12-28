<?php

use Psr\Log\LoggerInterface;

function _checkNewVersion(string $url, LoggerInterface $logger): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 40,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => 'web-TLO'
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = "Can't connect to GitHub";
        $logger->error($error, ['curl_error' => curl_error($ch), 'url' => $url]);
        return ['success' => false, 'response' => $error];
    }
    $responseHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $infoFromGitHub = $responseHttpCode == 200 ? json_decode($response, true) : false;

    if (empty($infoFromGitHub)) {
        $error = "Malformed response from GitHub";
        $logger->error($error, ['curl_error' => curl_error($ch), 'response' => $response]);
        return ['success' => false, 'response' => $error];
    }

    return
        [
            'success' => true,
            'response' => [
                'newVersionNumber' => $infoFromGitHub['name'],
                'newVersionLink' => $infoFromGitHub['zipball_url'],
                'whatsNew' => $infoFromGitHub['body'],
            ]
        ];
}
