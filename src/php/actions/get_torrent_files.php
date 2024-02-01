<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Timers;

try {
    Timers::start('download');

    include_once dirname(__FILE__) . '/../torrenteditor.php';
    include_once dirname(__FILE__) . '/../classes/download.php';

    $result = "";

    // список выбранных раздач
    if (empty($_POST['topic_hashes'])) {
        $result = "Выберите раздачи";
        throw new Exception();
    }

    // получение настроек
    $cfg = App::getSettings();

    // проверка настроек
    if (empty($cfg['api_key'])) {
        $result = "В настройках не указан хранительский ключ API";
        throw new Exception();
    }

    if (empty($cfg['user_id'])) {
        $result = "В настройках не указан хранительский ключ ID";
        throw new Exception();
    }

    // идентификатор подраздела
    $forum_id = $_POST['forum_id'] ?? 0;

    // нужна ли замена passkey
    $replace_passkey = (bool)($_POST['replace_passkey'] ?? false);

    // парсим список выбранных раздач
    parse_str($_POST['topic_hashes'], $topicHashes);

    // выбор каталога
    $torrent_files_path = !$replace_passkey ? $cfg['save_dir'] : $cfg['dir_torrents'];

    if (empty($torrent_files_path)) {
        $result = "В настройках не указан каталог для скачивания торрент-файлов";
        throw new Exception();
    }

    // дополнительный слэш в конце каталога
    if (!in_array(substr($torrent_files_path, -1), ['\\', '/'])) {
        $torrent_files_path .= !str_contains($torrent_files_path, '/') ? '\\' : '/';
    }

    // создание подкаталога
    if (
        !$replace_passkey
        && $cfg['savesub_dir']
    ) {
        $torrent_files_path .= 'tfiles_' . $forum_id . '_' . time() . substr($torrent_files_path, -1);
    }

    // создание каталогов
    Helper::makeDirRecursive($torrent_files_path);

    // шаблон для сохранения
    $torrent_files_path_pattern = "$torrent_files_path/[webtlo].h%s.torrent";
    if (PHP_OS == 'WINNT') {
        $torrent_files_path_pattern = mb_convert_encoding(
            $torrent_files_path_pattern,
            'Windows-1251',
            'UTF-8'
        );
    }

    // скачивание торрент-файлов
    $download = new TorrentDownload($cfg['forum_address']);

    $log_string = sprintf(
        "Выполняется скачивание торрент-файлов (%d шт), трекеры %s. ",
        count($topicHashes['topic_hashes']),
        $cfg['tor_for_user'] ? 'пользовательские' : 'хранительские'
    );
    if ($replace_passkey) {
        $log_string .=
            !empty($cfg['user_passkey'])
                ? 'Замена Passkey: ' .$cfg['user_passkey']
                : 'Passkey пуст.';
    }
    Log::append($log_string);

    // применяем таймауты
    $download->setUserConnectionOptions($cfg['curl_setopt']['forum']);

    $torrent_files_downloaded = [];
    foreach ($topicHashes['topic_hashes'] as $topicHash) {
        $data = $download->getTorrentFile($cfg['api_key'], $cfg['user_id'], $topicHash, $cfg['retracker']);
        if ($data === false) {
            continue;
        }
        // меняем пасскей
        if ($replace_passkey) {
            $torrent = new Torrent();
            if (!$torrent->load($data)) {
                Log::append("Error: $torrent->error ($topicHash).");
                break;
            }
            $trackers = $torrent->getTrackers();
            foreach ($trackers as &$tracker) {
                // Если задан пустой заменный ключ, то пихаем дефолтный 'ann?magnet'
                if (empty($cfg['user_passkey'])) {
                    $tracker = preg_replace('/(?<=ann\?).+$/', 'magnet', $tracker);
                } else {
                    $tracker = preg_replace('/(?<==)\w+$/', $cfg['user_passkey'], $tracker);
                }

                // Для обычных пользователей заменяем адрес трекера и тип ключа.
                if ($cfg['tor_for_user']) {
                    $tracker = preg_replace(['/(?<=\.)([-\w]+\.\w+)/', '/\w+(?==)/'], ['t-ru.org', 'pk'], $tracker);
                }
            }
            unset($tracker);
            $torrent->setTrackers($trackers);
            $data = $torrent->bencode();
        }
        // сохранить в каталог
        $file_put_contents = file_put_contents(
            sprintf(
                $torrent_files_path_pattern,
                $topicHash
            ),
            $data
        );
        if ($file_put_contents === false) {
            Log::append("Произошла ошибка при сохранении торрент-файла ($topicHash)");
            continue;
        }
        $torrent_files_downloaded[] = $topicHash;
    }
    unset($topicHashes);

    $result = sprintf(
        'Сохранено в каталоге "%s": %d шт. за %s.',
        $torrent_files_path,
        count($torrent_files_downloaded),
        Timers::getExecTime('download')
    );
} catch (Exception $e) {
    $result = $e->getMessage();
    Log::append($result);
}

echo json_encode([
    'log' => Log::get(),
    'result' => $result,
]);