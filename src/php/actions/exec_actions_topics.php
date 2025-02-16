<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Action\ClientApplyAction;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Module\Action\ClientAction;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Module\Action\ClientApplyOptions;

try {
    $result = '';

    $action = ClientAction::tryFrom($_POST['action'] ?? '');
    if ($action === null) {
        throw new Exception('Попытка выполнить неизвестное действие');
    }

    if (empty($_POST['topic_hashes'])) {
        throw new Exception('Выберите раздачи');
    }
    if (empty($_POST['tor_clients'])) {
        throw new Exception('В настройках не найдены торрент-клиенты');
    }

    // Выбранный торрент клиент.
    $selectedClient = (int) ($_POST['sel_client'] ?? 0);

    // Дополнительные параметры.
    $actionOptions = new ClientApplyOptions(
        label      : (string) ($_POST['label'] ?? ''),
        forceStart : (bool) ($_POST['remove_data'] ?? 0),
        removeFiles: (bool) ($_POST['force_start'] ?? 0),
    );

    parse_str($_POST['topic_hashes'], $topicHashes);
    $topicHashes = Helper::convertKeysToString((array) $topicHashes['topic_hashes']);

    $app = App::create();
    $log = $app->getLogger();

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
    if (isset($log)) {
        $log->error($result);
    } else {
        Log::append($result);
    }
}

echo json_encode([
    'log'    => Log::get(),
    'result' => $result,
], JSON_UNESCAPED_UNICODE);
