<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\TopicList\Rule\Factory;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\ValidationException;

$returnObject = [
    'size'     => 0,
    'count'    => 0,
    'ex_count' => 0,
    'ex_size'  => 0,
    'log'      => '',
    'validate' => '',
];

try {
    $app = App::create();

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

    $returnObject['topics']   = $topics->mergeList();
    $returnObject['size']     = $topics->size;
    $returnObject['count']    = $topics->count;
    $returnObject['ex_count'] = $topics->excluded->count;
    $returnObject['ex_size']  = $topics->excluded->size;
} catch (ValidationException $e) {
    $returnObject['log']      = $e->getMessage();
    $returnObject['validate'] = $e->getClass();
} catch (Exception $e) {
    $returnObject['log'] = $e->getMessage();
}

$returnObject['details'] = Log::get();

echo json_encode($returnObject, JSON_UNESCAPED_UNICODE);
