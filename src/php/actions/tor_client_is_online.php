<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Log;

try {
    include_once dirname(__FILE__) . '/../classes/clients.php';

    App::init();

    //~  0 - comment, 1 - type_client, 2 - host, 3 - port, 4 - login, 5 - passwd
    $torrentClient = $_POST['tor_client'];

    /**
     * @var utorrent|transmission|vuze|deluge|rtorrent|qbittorrent|flood $client
     */
    $client = new $torrentClient['type'](
        $torrentClient['ssl'],
        $torrentClient['hostname'],
        $torrentClient['port'],
        $torrentClient['login'],
        $torrentClient['password']
    );

    $status = sprintf(
        '<i class="fa fa-circle %s"></i>',
        $client->isOnline() ? 'text-success' : 'text-danger'
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    $status = sprintf('Не удалось проверить доступность торрент-клиента "%s"', $torrentClient['comment'] ?? 'unknown');
}

echo json_encode([
    'log'    => Log::get(),
    'status' => $status,
]);
