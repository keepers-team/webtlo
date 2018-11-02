<?php

try {

    include_once dirname(__FILE__) . '/../common.php';
    include_once dirname(__FILE__) . '/../classes/clients.php';

    //~  0 - comment, 1 - type_client, 2 - host, 3 - port, 4 - login, 5 - passwd
    $tor_client = $_POST['tor_client'];

    $client = new $tor_client[1](
        $tor_client[2],
        $tor_client[3],
        $tor_client[4],
        $tor_client[5],
        $tor_client[0]
    );

    $status = $client->is_online()
    ? "<i class=\"fa fa-circle text-success\"></i>\"${tor_client[0]}\" сейчас доступен"
    : "<i class=\"fa fa-circle text-danger\"></i>\"${tor_client[0]}\" сейчас недоступен";

    echo json_encode(
        array(
            'log' => Log::get(),
            'status' => $status,
        )
    );

} catch (Exception $e) {

    Log::append($e->getMessage());
    $status = "Не удалось проверить доступность торрент-клиента \"${tor_client[0]}\"";
    echo json_encode(
        array(
            'log' => Log::get(),
            'status' => $status,
        )
    );

}
