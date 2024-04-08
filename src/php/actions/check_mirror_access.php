<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\External\CheckMirrorAccess;
use KeepersTeam\Webtlo\Legacy\Log;

// Получаем контейнер.
$app = AppContainer::create();
$log = $app->getLogger();

$result = false;
try {
    // Получаем настройки.
    if (isset($_POST['cfg'])) {
        parse_str($_POST['cfg'], $cfg);
    }

    // Нет конфига - нет проверки.
    if (empty($cfg)) {
        throw new Exception('Пустой конфиг');
    }

    // Проверяемый url.
    $url = $_POST['url'] ?? null;

    // Свой проверяемый url.
    $url_custom = $_POST['url_custom'] ?? null;

    // Тип url.
    $url_type = $_POST['url_type'] ?? null;
    if (empty($url) || empty($url_type)) {
        throw new Exception('Не удалось определить тип проверки.');
    }

    if ($url === 'custom') {
        if (empty($url_custom)) {
            throw new Exception('Не удалось определить проверяемый адрес.');
        }
        $url = $url_custom;
    }

    $proxy = null;
    if ('true' === ($_POST['proxy'] ?? null)) {
        $proxy = Proxy::fromLegacy($cfg);
    }

    /** @var CheckMirrorAccess $check */
    $check = $app->get(CheckMirrorAccess::class);

    $result = $check->checkAddress($url_type, basename($url), $_POST['ssl'] === "true", $proxy);
} catch (Throwable $e) {
    $log->error($e->getMessage());
}

echo json_encode([
    'result' => $result ? '1' : '0',
    'log'    => Log::get(),
], JSON_UNESCAPED_UNICODE);
