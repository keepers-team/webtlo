<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\External\CheckMirrorAccess;

// Получаем контейнер.
$app = App::create();
$log = $app->getLogger();

$result = false;

try {
    $request = json_decode((string) file_get_contents('php://input'), true);

    // Получаем настройки.
    $cfg = $request['cfg'] ?? [];

    // Нет конфига - нет проверки.
    if (empty($cfg) || !is_array($cfg)) {
        throw new RuntimeException('Нет настроек для проверки доступности.');
    }

    // Проверяемый url.
    $url = $request['url'] ?? null;

    // Свой проверяемый url.
    $url_custom = $request['url_custom'] ?? null;

    // Тип url.
    $url_type = $request['url_type'] ?? null;
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
    if (true === ($request['proxy'] ?? null)) {
        $proxy = Proxy::fromLegacy(cfg: $cfg);
    }

    $check = $app->get(CheckMirrorAccess::class);

    $result = $check->checkAddress(type: $url_type, url: $url, proxy: $proxy);
} catch (Throwable $e) {
    $log->error($e->getMessage());
}

echo App::decorateJsonResponse(result: $result ? '1' : '0');
