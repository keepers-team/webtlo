<?php

try {
    include dirname(__FILE__) . '/../common.php';
    include dirname(__FILE__) . '/../classes/clients.php';
    include dirname(__FILE__) . '/../classes/reports.php';

    $cfg = get_settings();

    $unknownHashes = Db::query_database(
        'SELECT DISTINCT(Clients.hs) FROM Clients
        LEFT JOIN TopicsUntracked ON TopicsUntracked.hs = Clients.hs
        LEFT JOIN Topics ON Topics.hs = Clients.hs
        WHERE Topics.hs IS NULL AND TopicsUntracked.hs IS NULL',
        array(),
        true,
        PDO::FETCH_COLUMN
    );

    $topicsIDs = array();

    foreach ($cfg['clients'] as $torrentClientID => $torrentClientData) {
        $client = new $torrentClientData['cl'](
            $torrentClientData['ssl'],
            $torrentClientData['ht'],
            $torrentClientData['pt'],
            $torrentClientData['lg'],
            $torrentClientData['pw']
        );
        if ($client->isOnline() !== false) {
            $client->setUserConnectionOptions($cfg['curl_setopt']['torrent_client']);
            $torrents = $client->getAllTorrents();
            foreach ($torrents as $torrentHash => $torrentData) {
                if (
                    // NB! Может использоваться свой домен в рамках проекта «Мой Рутрекер»
                    preg_match('/rutracker/', $torrentData['comment'])
                    && $torrentData['done'] == 1
                    && in_array($torrentHash, $unknownHashes)
                ) {
                    $topicID = preg_replace('/.*?([0-9]*)$/', '$1', $torrentData['comment']);
                    $topicsIDs[] = $topicID;
                }
            }
        }
    }
    unset($unknownHashes);
    unset($torrents);

    $skippedStatuses = array(
        'закрыто',
        'поглощено'
    );

    $reports = new Reports($cfg['forum_address'], $cfg['tracker_login'], $cfg['tracker_paswd']);
    $reports->curl_setopts($cfg['curl_setopt']['forum']);

    foreach ($topicsIDs as $topicID) {
        $data = $reports->getDataUnregisteredTopic($topicID);
        if (!is_array($data)) {
            continue;
        }
        if (in_array($data['last_status'], $skippedStatuses)) {
            continue;
        }
        $currentCategory = explode(' » ', $data['current_forum']);
        $categoryTitle = empty($data['original_forum']) ? $data['current_forum'] : $data['original_forum'];
        $output[$currentCategory[0]][] = $categoryTitle  . ' | [url=viewtopic.php?t=' . $topicID . ']' .
            $topicID . '[/url] | ' . $data['last_status'] . ' | ' . $data['who_transferred'];
    }
    unset($topicsIDs);

    ksort($output, SORT_NATURAL);

    foreach ($output as $categoryName => $categoryData) {
        asort($categoryData, SORT_NATURAL);
        echo '[b]' . $categoryName . '[/b][hr]</br>';
        foreach ($categoryData as $topicData) {
            echo $topicData . '</br>';
        }
        echo '</br>';
    }
} catch (Exception $e) {
    Log::append($e->getMessage());
    echo Log::get();
}
