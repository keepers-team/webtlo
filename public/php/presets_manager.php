<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\Helper;

$presetsFile = Helper::getStorageDir() . '/presets.json';

// Инициализация пустого файла, если его нет.
if (!file_exists($presetsFile)) {
    file_put_contents($presetsFile, '{}');
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Чтение текущих пресетов.
$presets = json_decode((string) file_get_contents($presetsFile), true);
if (!is_array($presets)) {
    $presets = [];
}

header('Content-Type: application/json');

switch ($action) {
    case 'list':
        echo json_encode(array_keys($presets));
        exit;

    case 'load':
        $name = $_GET['name'] ?? '';
        if (isset($presets[$name])) {
            echo json_encode($presets[$name]);
        } else {
            http_response_code(404);

            echo json_encode(['error' => 'Preset not found']);
        }

        exit;

    case 'save':
        $name = $_POST['name'] ?? '';
        $data = isset($_POST['data']) ? json_decode($_POST['data'], true) : null;

        if (!$name || !$data) {
            http_response_code(400);

            echo json_encode(['error' => 'Invalid data']);

            exit;
        }

        // Проверка на дубликат: можно перезаписывать или выдавать ошибку.
        $presets[$name] = $data;
        file_put_contents($presetsFile, json_encode($presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        echo json_encode(['success' => true]);

        exit;

    case 'delete':
        $name = $_POST['name'] ?? '';

        if (isset($presets[$name])) {
            unset($presets[$name]);
            file_put_contents($presetsFile, json_encode($presets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            echo json_encode(['success' => true]);
        } else {
            http_response_code(404);

            echo json_encode(['error' => 'Preset not found']);
        }
        exit;

    default:
        http_response_code(400);

        echo json_encode(['error' => 'Unknown action']);
}
