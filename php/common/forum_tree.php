<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_url'], $cfg['api_key']);
}

// обновление дерева подразделов
$forum_tree_update = Db::query_database(
    "SELECT strftime('%s', 'now') - ud FROM UpdateTime WHERE id = ?",
    array(8888),
    true,
    PDO::FETCH_COLUMN
);

if (
    empty($forum_tree_update)
    || $forum_tree_update[0] > 3600
) {

    // получение дерева подразделов
    $forum_tree = $api->get_cat_forum_tree();

    if (empty($forum_tree['result'])) {
        throw new Exception("Error: Не удалось получить дерево подразделов");
    }

    $forum_tree_update_current = $forum_tree['update_time'];

    foreach ($forum_tree['result']['c'] as $cat_id => $cat_title) {
        foreach ($forum_tree['result']['tree'][$cat_id] as $forum_id => $subforum) {
            // разделы
            $forum_title = $cat_title . ' » ' . $forum_tree['result']['f'][$forum_id];
            $forums[$forum_id] = array(
                'na' => $forum_title,
                'qt' => 0,
                'si' => 0,
            );
            // подразделы
            foreach ($subforum as $subforum_id) {
                $subforum_title = $cat_title . ' » ' . $forum_tree['result']['f'][$forum_id] . ' » ' . $forum_tree['result']['f'][$subforum_id];
                $forums[$subforum_id] = array(
                    'na' => $subforum_title,
                    'qt' => 0,
                    'si' => 0,
                );
            }
        }
    }
    unset($forum_tree);

    // получение количества и веса раздач по разделам
    $forum_size = $api->forum_size();

    if (empty($forum_size['result'])) {
        throw new Exception("Error: Не удалось получить количество и вес раздач по разделам");
    }

    $forum_size_update_current = $forum_size['update_time'];

    foreach ($forum_size['result'] as $forum_id => $values) {
        if (empty($values)) {
            continue;
        }
        if (isset($forums[$forum_id])) {
            $forums[$forum_id] = array_merge(
                $forums[$forum_id],
                array_combine(
                    array('qt', 'si'),
                    $values
                )
            );
        }
    }

    // создаём временную таблицу
    Db::query_database(
        'CREATE TEMP TABLE ForumsNew AS
        SELECT id,na,qt,si FROM Forums WHERE 0 = 1'
    );

    // отправляем в базу данных
    $forums = array_chunk($forums, 500, true);

    foreach ($forums as $forums) {
        $select = Db::combine_set($forums);
        Db::query_database("INSERT INTO temp.ForumsNew (id,na,qt,si) $select");
        unset($select);
    }
    unset($forums);

    Log::append("Обновление дерева подразделов...");

    Db::query_database('INSERT INTO Forums (id,na,qt,si) SELECT id,na,qt,si FROM temp.ForumsNew');

    Db::query_database('DELETE FROM Forums WHERE id IN (
        SELECT Forums.id FROM Forums
        LEFT JOIN temp.ForumsNew ON Forums.id = temp.ForumsNew.id
        WHERE temp.ForumsNew.id IS NULL
    )');

    // время обновления дерева подразделов
    Db::query_database(
        "INSERT INTO UpdateTime (id,ud) SELECT 8888,?",
        array($forum_tree_update_current)
    );

    unset($forum_tree_update_current);
    unset($forum_tree_update);

}
