<?php

try {
    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/clients.php';

    //~  0 - comment, 1 - type_client, 2 - host, 3 - port, 4 - login, 5 - passwd
    $torrentClient = $_POST['tor_client'];

    /**
     * @var utorrent|transmission|vuze|deluge|ktorrent|rtorrent|qbittorrent $client
     */
    $client = new $torrentClient['type'](
        $torrentClient['hostname'],
        $torrentClient['port'],
        $torrentClient['login'],
        $torrentClient['password']
    );

    $status = $client->isOnline()
        ? '<i class="fa fa-circle text-success"></i>"' . $torrentClient['comment'] . '" сейчас доступен'
        : '<i class="fa fa-circle text-danger"></i>"' . $torrentClient['comment'] . '" сейчас недоступен';

    echo json_encode(
        array(
            'log' => Log::get(),
            'status' => $status,
        )
    );
} catch (Exception $e) {
    Log::append($e->getMessage());
    $status = 'Не удалось проверить доступность торрент-клиента "' . $torrentClient['comment'] . '"';
    echo json_encode(
        array(
            'log' => Log::get(),
            'status' => $status,
        )
    );
}
