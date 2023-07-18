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
            'na' => $forumTitle,
            'qt' => 0,
            'si' => 0,
        ];
        // подразделы
        foreach ($subForum as $subForumId) {
            $subForumTitle = $catTitle . ' » ' . $forumTree['result']['f'][$forum_id] . ' » ' . $forumTree['result']['f'][$subForumId];
            $forums[$subForumId] = [
                'na' => $subForumTitle,
                'qt' => 0,
                'si' => 0,
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
                ['qt', 'si'],
                $values
            )
        );
    }
}

if (isset($forums) && count($forums)) {
    // создаём временную таблицу
    Db::query_database(
        'CREATE TEMP TABLE ForumsNew AS
    SELECT id,na,qt,si FROM Forums WHERE 0 = 1'
    );

    // отправляем в базу данных
    $forumsChunks = array_chunk($forums, 500, true);

    foreach ($forumsChunks as $forumsParts) {
        $select = Db::combine_set($forumsParts);
        Db::query_database("INSERT INTO temp.ForumsNew (id,na,qt,si) $select");
        unset($forumsParts, $select);
    }

    Log::append("Обновление дерева подразделов...");

    Db::query_database('INSERT INTO Forums (id,na,qt,si) SELECT id,na,qt,si FROM temp.ForumsNew');

    Db::query_database('DELETE FROM Forums WHERE id IN (
        SELECT Forums.id FROM Forums
        LEFT JOIN temp.ForumsNew ON Forums.id = temp.ForumsNew.id
        WHERE temp.ForumsNew.id IS NULL
    )');


    // Записываем время обновления.
    set_last_update_time(FORUM_TREE_UPDATE, $treeUpdateTime);

    Log::append(sprintf(
        'Обновление дерева подразделов завершено за %s, обработано подразделов: %d шт',
        Timers::getExecTime('forum_tree'),
        count($forums)
    ));
    unset($forums, $forumsChunks, $treeUpdateTime);
}
