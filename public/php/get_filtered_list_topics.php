<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\TopicList\HtmlFormatter;
use KeepersTeam\Webtlo\TopicList\JsonFormatter;
use KeepersTeam\Webtlo\TopicList\Rule\Factory;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\ValidationException;

$response = [
    'result'         => '',
    'validate'       => '',

    'topics_size'    => 0,
    'topics_count'   => 0,
    'excluded_count' => 0,
    'excluded_size'  => 0,
];

// Подключаем контейнер.
$app = App::create();

try {
    $request = json_decode((string) file_get_contents('php://input'), true);

    $listingId = $request['listing_id'] ?? null;
    if (!is_numeric($listingId)) {
        throw new RuntimeException("Некорректный идентификатор подраздела/разворота: $listingId");
    }

    // Кодировка для regexp.
    mb_regex_encoding('UTF-8');

    // Список добавляемых раздач (info_hash).
    if (empty($request['filter']) || !is_array($request['filter'])) {
        throw new RuntimeException('Отсутствуют параметры фильтрации раздач.');
    }

    // Получаем параметры фильтра.
    $filter = Helper::convertKeysToString(array: $request['filter']);

    // Проверяем наличие сортировки.
    $sorting = Validate::sortFilter(filter: $filter);

    $columns = $request['columns'] ?? [];

    $responseType = $request['response_type'] ?? 'html';
    if (!in_array($responseType, ['html', 'json'], true)) {
        throw new RuntimeException('Некорректный тип ответа');
    }

    $ruleFactory = $app->get(Factory::class);

    // Получаем нужные правила поиска раздач.
    $ruleSet = $ruleFactory->getRule(listingId: (int) $listingId);

    // Ищем и форматируем раздачи.
    $topics = $ruleSet->getTopics(filter: $filter, sort: $sorting);

    if ($responseType === 'json') {
        $formatter = new JsonFormatter();
    } else {
        $formatter = $app->get(HtmlFormatter::class);
    }

    $result = $formatter->format(topics: $topics, columns: (array) $columns);

    // Формируем ответ.
    $response = [
        'response_type' => $responseType,
        ...$response,
        ...$result,
    ];
} catch (ValidationException $e) {
    $response['result']   = $e->getMessage();
    $response['validate'] = $e->getClass();
} catch (Exception $e) {
    $response['result'] = $e->getMessage();
}

echo App::decorateJsonResponse(result: $response);
