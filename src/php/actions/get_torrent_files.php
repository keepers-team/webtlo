<?php

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\TorrentEditor;
use KeepersTeam\Webtlo\Timers;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    Timers::start('download');

    $result = '';

    // список выбранных раздач
    if (empty($_POST['topic_hashes'])) {
        throw new RuntimeException('Выберите раздачи');
    }

    $cfg = $app->getLegacyConfig();

    // Ключи для скачивания файлов.
    $apiCredentials = $app->get(ApiCredentials::class);

    // идентификатор подраздела
    $forum_id = $_POST['forum_id'] ?? 0;

    // нужна ли замена passkey
    $replace_passkey = (bool) ($_POST['replace_passkey'] ?? false);

    $passkeyValue   = (string) $cfg['user_passkey'];
    $forRegularUser = (bool) ($cfg['tor_for_user'] ?? false);

    // парсим список выбранных раздач
    parse_str($_POST['topic_hashes'], $topicHashes);

    /** @var string[] $topicHashes */
    $topicHashes = array_map('strval', (array) $topicHashes['topic_hashes']);

    // выбор каталога
    $torrent_files_path = !$replace_passkey ? $cfg['save_dir'] : $cfg['dir_torrents'];

    if (empty($torrent_files_path)) {
        throw new Exception('В настройках не указан каталог для скачивания торрент-файлов');
    }

    // дополнительный слэш в конце каталога
    if (!in_array(substr($torrent_files_path, -1), ['\\', '/'])) {
        $torrent_files_path .= !str_contains($torrent_files_path, '/') ? '\\' : '/';
    }

    // создание подкаталога
    if (!$replace_passkey && $cfg['savesub_dir']) {
        $torrent_files_path .= 'tfiles_' . $forum_id . '_' . time() . substr($torrent_files_path, -1);
    }

    // создание каталогов
    Helper::makeDirRecursive($torrent_files_path);

    // шаблон для сохранения
    $torrent_files_path_pattern = Helper::normalizePathEncoding("$torrent_files_path/[webtlo].h%s.torrent");

    $forumClient = $app->getForumClient();
    if (!$forumClient->checkConnection()) {
        throw new RuntimeException('Ошибка подключения к форуму.');
    }

    // Записываем ключи доступа к API.
    $forumClient->setApiCredentials(apiCredentials: $apiCredentials);

    $log_string = sprintf(
        'Выполняется скачивание торрент-файлов (%d шт), трекеры %s. ',
        count($topicHashes),
        $forRegularUser ? 'пользовательские' : 'хранительские'
    );
    if ($replace_passkey) {
        $log_string .= !empty($passkeyValue) ? "Замена Passkey: [$passkeyValue]" : 'Passkey пуст.';
    }
    $log->info($log_string);

    $addRetracker = (bool) ($cfg['retracker'] ?? false);

    $torrent_files_downloaded = [];
    foreach ($topicHashes as $topicHash) {
        $data = $forumClient->downloadTorrent(infoHash: $topicHash, addRetracker: $addRetracker);
        if ($data === null) {
            continue;
        }

        // Меняем ключ для трекера.
        if ($replace_passkey) {
            try {
                $torrent = TorrentEditor::loadFromStream(logger: $log, stream: $data);
                $torrent->replaceTrackers(passkey: $passkeyValue, regularUser: $forRegularUser);

                $data = $torrent->getTorrent()->storeToString();

                unset($torrent);
            } catch (Exception $e) {
                $log->warning('Ошибка редактирования торрента', ['error' => $e->getMessage()]);

                continue;
            }
        } else {
            $data = $data->getContents();
        }

        if (empty($data)) {
            continue;
        }

        // сохранить в каталог
        $fileSaved = file_put_contents(
            sprintf(
                $torrent_files_path_pattern,
                $topicHash
            ),
            $data
        );
        if ($fileSaved === false) {
            $log->warning("Произошла ошибка при сохранении торрент-файла ($topicHash)");

            continue;
        }

        $torrent_files_downloaded[] = $topicHash;

        unset($topicHash, $data, $fileSaved);
    }
    unset($topicHashes);

    $result = sprintf(
        'Сохранено в каталоге "%s": %d шт. за %s.',
        $torrent_files_path,
        count($torrent_files_downloaded),
        Timers::getExecTime('download')
    );

    $log->info($result);
} catch (Exception $e) {
    $result = $e->getMessage();
    $log->error($result);
} finally {
    $log->info('-- DONE --');
}

echo json_encode([
    'log' => $app->getLoggerRecords(),
    'result' => $result,
], JSON_UNESCAPED_UNICODE);
