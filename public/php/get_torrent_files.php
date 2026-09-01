<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\TorrentDownload;
use KeepersTeam\Webtlo\Helper;
use KeepersTeam\Webtlo\Module\TorrentEditor;
use KeepersTeam\Webtlo\Timers;

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $request = json_decode((string) file_get_contents('php://input'), true);

    Timers::start('download');

    $result = '';

    // Список добавляемых раздач (info_hash).
    if (empty($request['topic_hashes']) || !is_array($request['topic_hashes'])) {
        throw new RuntimeException('Выберите раздачи для скачивания.');
    }

    // Идентификатор подраздела.
    $forum_id = $request['forum_id'] ?? 0;

    // Нужна ли замена PASSKEY.
    $replace_passkey = (bool) ($request['replace_passkey'] ?? false);

    $downloadOptions = $app->get(TorrentDownload::class);

    $passkeyValue   = $downloadOptions->replacePassKey;
    $forRegularUser = $downloadOptions->forRegularUser;

    $topicHashes = Helper::convertKeysToString(array: $request['topic_hashes']);

    // выбор каталога
    $torrent_files_path = !$replace_passkey
        ? $downloadOptions->folder
        : $downloadOptions->folderReplace;

    if (empty($torrent_files_path)) {
        throw new Exception('В настройках не указан каталог для скачивания торрент-файлов');
    }

    // дополнительный слэш в конце каталога
    if (!in_array(substr($torrent_files_path, -1), ['\\', '/'], true)) {
        $torrent_files_path .= !str_contains($torrent_files_path, '/') ? '\\' : '/';
    }

    // создание подкаталога
    if (!$replace_passkey && $downloadOptions->subFolder) {
        $torrent_files_path .= 'tfiles_' . $forum_id . '_' . time() . substr($torrent_files_path, -1);
    }

    // создание каталогов
    Helper::makeDirRecursive($torrent_files_path);

    // шаблон для сохранения
    $torrent_files_path_pattern = Helper::normalizePathEncoding("$torrent_files_path/[webtlo].h%s.torrent");

    $forumClient = $app->getForumClient();

    $log_string = sprintf(
        'Выполняется скачивание торрент-файлов (%d шт), трекеры %s. ',
        count($topicHashes),
        $forRegularUser ? 'пользовательские' : 'хранительские'
    );
    if ($replace_passkey) {
        $log_string .= !empty($passkeyValue) ? "Замена Passkey: [$passkeyValue]" : 'Passkey пуст.';
    }
    $log->info($log_string);

    $torrent_files_downloaded = [];
    foreach ($topicHashes as $topicHash) {
        $data = $forumClient->downloadTorrent(infoHash: $topicHash, addRetracker: $downloadOptions->addRetracker);
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

echo App::decorateJsonResponse($result);
