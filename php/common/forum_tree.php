<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

Timers::start('forum_tree');
/** Ид подраздела обновления дерева подразделов  */
const FORUM_TREE_UPDATE = 8888;

// Проверяем время последнего обновления.
if (!check_update_available(FORUM_TREE_UPDATE)) {
    Log::append('Обновление списка подразделов не требуется.');
    return;
}

// получение настроек
if (!isset($cfg)) {
    $cfg = get_settings();
}

// подключаемся к api
if (!isset($api)) {
    $api = new Api($cfg['api_address'], $cfg['api_key']);
    // применяем таймауты
    $api->setUserConnectionOptions($cfg['curl_setopt']['api']);
}

// получение дерева подразделов
$forumTree = $api->getCategoryForumTree();

if (empty($forumTree['result'])) {
    throw new Exception("Error: Не удалось получить дерево подразделов");
}

$treeUpdateTime = $forumTree['update_time'];

foreach ($forumTree['result']['c'] as $catId => $catTitle) {
    foreach ($forumTree['result']['tree'][$catId] as $forum_id => $subForum) {
        // разделы
        $forumTitle = $catTitle . ' » ' . $forumTree['result']['f'][$forum_id];
        $forums[$forum_id] = [
            'name'     => $forumTitle,
            'quantity' => 0,
            'size'     => 0,
        ];
        // подразделы
        foreach ($subForum as $subForumId) {
            $subForumTitle = $catTitle . ' » ' . $forumTree['result']['f'][$forum_id] . ' » ' . $forumTree['result']['f'][$subForumId];
            $forums[$subForumId] = [
                'name'     => $subForumTitle,
                'quantity' => 0,
                'size'     => 0,
            ];
            unset($subForumId, $subForumTitle);
        }
        unset($forum_id, $forumTitle, $subForum);
    }
    unset($catId, $catTitle);
}
unset($forumTree);

// получение количества и веса раздач по разделам
$forum_size = $api->getCategoryForumVolume();

if (empty($forum_size['result'])) {
    throw new Exception("Error: Не удалось получить количество и вес раздач по разделам");
}

foreach ($forum_size['result'] as $forum_id => $values) {
    if (empty($values)) {
        continue;
    }
    if (isset($forums[$forum_id])) {
        $forums[$forum_id] = array_merge(
            $forums[$forum_id],
            array_combine(
                ['quantity', 'size'],
                $values
            )
        );
    }
}

if (isset($forums) && count($forums)) {
    // Параметры таблиц.
    $FT = (object)[
        'table'   => 'Forums',
        'temp'    => Db::temp_copy_table('Forums'),
    ];

    // отправляем в базу данных
    $forumsChunks = array_chunk($forums, 500, true);

    foreach ($forumsChunks as $forumsParts) {
        Db::table_insert_dataset($FT->temp, $forumsParts);
        unset($forumsParts);
    }

    Log::append("Обновление дерева подразделов...");

    // Переносим данные из временной таблицы в основную.
    Db::table_insert_temp($FT->table, $FT->temp);

    // Удаляем неактуальные записи.
    Db::query_database("
        DELETE FROM $FT->table WHERE id IN (
            SELECT upd.id
            FROM $FT->table AS upd
            LEFT JOIN $FT->temp AS tmp ON upd.id = tmp.id
            WHERE tmp.id IS NULL
        )
    ");

    // Записываем время обновления.
    set_last_update_time(FORUM_TREE_UPDATE, $treeUpdateTime);

    Log::append(sprintf(
        'Обновление дерева подразделов завершено за %s, обработано подразделов: %d шт',
        Timers::getExecTime('forum_tree'),
        count($forums)
    ));
    unset($forums, $forumsChunks, $treeUpdateTime);
}
