<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/api.php';

use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Module\CloneTable;
use KeepersTeam\Webtlo\Module\LastUpdate;

Timers::start('forum_tree');

Log::append('Info: Начато обновление дерева подразделов...');
// Проверяем время последнего обновления.
if (!LastUpdate::checkUpdateAvailable(UpdateMark::FORUM_TREE->value)) {
    Log::append('Notice: Обновление дерева подразделов не требуется.');
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
    $tabForums = CloneTable::create('Forums');

    // отправляем в базу данных
    $tabForums->cloneFillChunk($forums);

    // Переносим данные из временной таблицы в основную.
    $tabForums->moveToOrigin();

    // Удаляем неактуальные записи.
    $tabForums->clearUnusedRows();

    // Записываем время обновления.
    LastUpdate::setTime(UpdateMark::FORUM_TREE->value, $treeUpdateTime);

    Log::append(sprintf(
        'Info: Обновление дерева подразделов завершено за %s, обработано подразделов: %d шт',
        Timers::getExecTime('forum_tree'),
        count($forums)
    ));
    unset($forums, $forumsChunks, $treeUpdateTime);
}
