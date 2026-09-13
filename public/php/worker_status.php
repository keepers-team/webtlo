<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

// This script runs in its own FPM pool, so it can answer while the app pool is full.
$request = curl_init('http://127.0.0.1:8081/fpm-status');
if ($request === false) {
    http_response_code(503);
    echo '{"error":"status_unavailable"}';
    exit;
}

curl_setopt_array($request, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT_MS => 500,
    CURLOPT_TIMEOUT_MS => 1500,
]);

$response = curl_exec($request);
$httpCode = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
curl_close($request);

$status = is_string($response) && $httpCode === 200
    ? json_decode($response, true)
    : null;

$fields = [
    'active_processes' => 'active processes',
    'idle_processes'   => 'idle processes',
    'listen_queue'     => 'listen queue',
];

$counters = [];
foreach ($fields as $name => $source) {
    if (!is_array($status) || !isset($status[$source]) || !is_int($status[$source]) || $status[$source] < 0) {
        http_response_code(503);
        echo '{"error":"status_unavailable"}';
        exit;
    }

    $counters[$name] = $status[$source];
}

// Read the configured limit rather than assuming the Docker default of two.
$poolConfig = @file_get_contents('/etc/php82/php-fpm.d/www.conf');
$counters['max_children'] = is_string($poolConfig)
    && preg_match('/^\s*pm\.max_children\s*=\s*(\d+)\s*$/m', $poolConfig, $matches)
        ? (int) $matches[1]
        : 0;

echo json_encode($counters, JSON_THROW_ON_ERROR);
