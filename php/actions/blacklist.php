<?php

try {

    include_once dirname(__FILE__) . '/../common.php';

    if (empty($_POST['topics_ids'])) {
        $result = "Выберите раздачи";
        throw new Exception();
    }

    parse_str($_POST['topics_ids'], $topics_ids);

    $value = empty($_POST['value']) ? 0 : 1;

    $topics_ids = array_chunk($topics_ids['topics_ids'], 500);

    foreach ($topics_ids as $topics_ids) {
        if ($value == 0) {
            $in = str_repeat('?,', count($topics_ids) - 1) . '?';
            Db::query_database(
                "DELETE FROM Blacklist WHERE id IN ($in)",
                $topics_ids
            );
        } elseif ($value == 1) {
            $select = str_repeat('SELECT ? UNION ALL ', count($topics_ids) - 1) . ' SELECT ?';
            Db::query_database(
                "INSERT INTO Blacklist (id) $select",
                $topics_ids
            );
        }
    }

    echo 'Обновление "чёрного списка" раздач успешно завершено';

} catch (Exception $e) {

    echo $e->getMessage();

}
