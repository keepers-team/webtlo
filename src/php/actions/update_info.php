<?php

$update_result = [
    'result' => '',
];
try {
    // Список задач, которых можно запустить.
    $pairs = [
        // списки раздач в хранимых подразделах
        'subsections' => 'update_subsections',
        // список высокоприоритетных раздач
        'priority'    => 'high_priority_topics',
        // раздачи других хранителей
        'keepers'     => 'keepers',
        // раздачи в торрент-клиентах
        'clients'     => 'tor_clients',
    ];

    $process = $_GET['process'] ?: null;
    if (null !== $process && 'all' !== $process) {
        $pairs = array_filter($pairs, fn ($el) => $el === $process, ARRAY_FILTER_USE_KEY);
    }
    // Запускаем задачи по очереди.
    foreach ($pairs as $fileName) {
        include_once sprintf('%s/../common/%s.php', dirname(__FILE__), $fileName);
    }
} catch (Exception $e) {
    Log::append($e->getMessage());
}
Log::append('-- DONE --');

// Выводим лог
$update_result['log'] = Log::get();

echo json_encode($update_result, JSON_UNESCAPED_UNICODE);
