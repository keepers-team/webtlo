<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\ClientApplyAction;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\Action\ClientAction;
use KeepersTeam\Webtlo\Module\Action\ClientApplyOptions;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $result = '';

    $request = json_decode((string) file_get_contents('php://input'), true);

    $action = ClientAction::tryFrom($request['action'] ?? '');
    if ($action === null) {
        throw new Exception('Попытка выполнить неизвестное действие');
    }

    if (empty($request['topic_hashes'])) {
        throw new Exception('Выберите раздачи');
    }
    if (empty($request['tor_clients'])) {
        throw new Exception('В настройках не найдены торрент-клиенты');
    }

    // Выбранный торрент клиент.
    $selectedClient = (int) ($request['sel_client'] ?? 0);

    // Дополнительные параметры.
    $actionOptions = new ClientApplyOptions(
        label      : (string) ($request['label'] ?? ''),
        forceStart : (bool) ($request['force_start'] ?? 0),
        removeFiles : (bool) ($request['remove_data'] ?? 0),
    );

    parse_str($request['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array) $topicHashes['topic_hashes']);

    /** @var ClientApplyAction $actionApply */
    $actionApply = $app->get(ClientApplyAction::class);

    $actionApply->process(
        action        : $action,
        hashes        : $topicHashes,
        selectedClient: $selectedClient,
        params        : $actionOptions,
    );

    $result = "Действие '$action->value' выполнено. За подробностями обратитесь к журналу";
} catch (Exception $e) {
    $result = $e->getMessage();
    $log->error($result);
}

echo json_encode([
    'log'    => $app->getLoggerRecords(),
    'result' => $result,
], JSON_UNESCAPED_UNICODE);
