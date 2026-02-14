<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    // 0 - comment, 1 - type_client, 2 - host, 3 - port, 4 - login, 5 - passwd
    $params = $_POST['tor_client'];

    $clientFactory = $app->getClientFactory();

    $isOnline = false;

    try {
        $client   = $clientFactory->fromFrontProperties($params);
        $isOnline = $client->isOnline();
    } catch (RuntimeException $e) {
        $log->warning($e->getMessage());
    }

    $status = sprintf(
        '<i class="fa fa-circle %s"></i>',
        $isOnline ? 'text-success' : 'text-danger'
    );
} catch (Exception $e) {
    $status = sprintf('Не удалось проверить доступность торрент-клиента "%s"', $params['comment'] ?? 'unknown');
}

echo json_encode([
    'log'    => $app->getLoggerRecords(),
    'status' => $status,
]);
