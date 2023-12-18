<?php

declare(strict_types=1);

use KeepersTeam\Webtlo\TopicList\Rule\Factory;
use KeepersTeam\Webtlo\TopicList\Validate;
use KeepersTeam\Webtlo\TopicList\Output;
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
    include_once dirname(__FILE__) . '/../common.php';

    $forum_id = $_POST['forum_id'] ?? null;
    if (!is_numeric($forum_id)) {
        throw new Exception("Некорректный идентификатор подраздела: $forum_id");
    }

    // Кодировка для regexp.
    mb_regex_encoding('UTF-8');

    // Получаем параметры фильтра.
    $filter = [];
    parse_str($_POST['filter'], $filter);

    // Проверяем наличие сортировки.
    $sorting = Validate::sortFilter($filter);

    // Получаем настройки.
    $cfg = get_settings();

    // Собираем фабрику.
    $ruleFactory = new Factory($cfg, new Output($cfg, $cfg['forum_address'] ?? ''));

    //  0 - из других подразделов
    // -1 - незарегистрированные
    // -2 - черный список
    // -3 - все хранимые
    // -4 - дублирующиеся раздачи
    // -5 - высокоприоритетные раздачи
    // -6 - раздачи своим по спискам

    // Получаем нужные правила поиска раздач.
    $module = $ruleFactory->getRule((int)$forum_id);

    // Ищем раздачи.
    $topics = $module->getTopics($filter, $sorting);

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

echo json_encode($returnObject, JSON_UNESCAPED_UNICODE);