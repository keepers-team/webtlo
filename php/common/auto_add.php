<?php

include_once dirname(__FILE__) . '/../common.php';
include_once dirname(__FILE__) . '/../classes/filter.php';
include_once dirname(__FILE__) . '/../actions/get_filtered_list_topics_function.php';
include_once dirname(__FILE__) . '/../actions/add_topics_to_client_function.php';

Log::append('Начат процесс добавления новых раздач в торрент-клиенты...');

// получение настроек
$cfg = get_settings();
$filter = new Filter();

if (isset($cfg['subsections'])) {
    foreach ($cfg['subsections'] as $forumID => $forumData) {
        if ($forumData['auto_add_topics'] == 1) {
            Log::append('Начат процесс добавления новых раздач в торрент-клиент для подраздела ' . $forumID);
            if (!empty($filter->filterOptions[$forumID])) {
                $_POST['forum_id'] = $forumID;
                $_POST['filter'] = json_decode($filter->filterOptions[$forumID], true);
                $tessst = array_map(function ($key, $value) {
                    return [$value['name'] => $value['value']];
                }, array_keys($_POST['filter']), array_values($_POST['filter']));
                $a = 1;

                $_POST['filter'] = http_build_query(
                    array_merge_recursive(
                        ...array_map(function ($key, $value) {
                            return [str_replace("[]", "", $value['name']) => $value['value']];
                        }, array_keys($_POST['filter']), array_values($_POST['filter']))
                    )
                );
                ob_start();
                get_filtered_list_topics();
                $topics_list = json_decode(ob_get_clean(), true);
                if (!empty($topics_list['topics'])) {
                    preg_match_all('/(?<=value=").{40}/i', $topics_list['topics'], $_POST['topic_hashes']);
                    $_POST['topic_hashes'] = http_build_query(array_combine(["topic_hashes"], $_POST['topic_hashes']));
                    add_topics_to_client();
                } else {
                    Log::append("Отсутствуют раздачи для добавления");
                }
                Log::append('Процесс добавления торрентов для подраздела ' . $forumID . " завершен");
            } else {
                Log::append('Не удалось прочитать параметры фильтра из файла для подраздела:' . $forumID);
                Log::append('Процесс добавления торрентов для подраздела ' . $forumID . " завершен");
            }
        }
    }
}