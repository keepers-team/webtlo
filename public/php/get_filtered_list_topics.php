<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\TopicList\Rule\Factory;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\ValidationException;

$response = [
    'result'   => '',
    'validate' => '',

    'topics_size'    => 0,
    'topics_count'   => 0,
    'excluded_count' => 0,
    'excluded_size'  => 0,
];

// Подключаем контейнер.
$app = App::create();

try {
    $forum_id = $_POST['forum_id'] ?? null;
    if (!is_numeric($forum_id)) {
        throw new Exception("Некорректный идентификатор подраздела: $forum_id");
    }

    // Кодировка для regexp.
    mb_regex_encoding('UTF-8');

    // Получаем параметры фильтра.
    $filter = [];
    parse_str($_POST['filter'], $filter);
    $filter = Helper::convertKeysToString($filter);

    // Проверяем наличие сортировки.
    $sorting = Validate::sortFilter($filter);

    /** @var Factory $ruleFactory */
    $ruleFactory = $app->get(Factory::class);

    //  0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые
    // -4 - дублирующиеся раздачи
    // -5 - высокоприоритетные раздачи
    // -6 - раздачи своим по спискам

    // Получаем нужные правила поиска раздач.
    $ruleSet = $ruleFactory->getRule(forumId: (int) $forum_id);

    // Ищем раздачи.
    $topics = $ruleSet->getTopics(filter: $filter, sort: $sorting);

    // Формируем ответ.
    $response['topics'] = $topics->mergeList();

    $response['topics_size']    = $topics->size;
    $response['topics_count']   = $topics->count;
    $response['excluded_count'] = $topics->excluded->count;
    $response['excluded_size']  = $topics->excluded->size;
} catch (ValidationException $e) {
    $response['result']   = $e->getMessage();
    $response['validate'] = $e->getClass();
} catch (Exception $e) {
    $response['result'] = $e->getMessage();
}

echo App::decorateJsonResponse($response);
