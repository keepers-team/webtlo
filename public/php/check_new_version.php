<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\WebTLO;

$result = array_fill_keys(['newVersionNumber', 'newVersionLink', 'whatsNew'], '');

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    $wbtApi = WebTLO::getVersion();

    if (empty($wbtApi->releaseApi)) {
        throw new Exception('Невозможно проверить наличие новой версии.');
    }

    try {
        $client   = new Client();
        $response = $client->get($wbtApi->releaseApi, [
            'timeout'         => 40,
            'connect_timeout' => 40,
        ]);
    } catch (GuzzleException $e) {
        $log->warning('CURL ошибка: ' . $e->getMessage());

        throw new Exception('Невозможно связаться с api.github.com');
    }

    $latestRelease = json_decode($response->getBody()->getContents(), true);

    if (empty($latestRelease)) {
        throw new Exception('Не удалось получить актуальную версию с GitHub');
    }

    $link = getReleaseLink($wbtApi->installation, $latestRelease);

    $result['newVersionNumber'] = $latestRelease['name'];
    $result['newVersionLink']   = $link;
    $result['whatsNew']         = getReleaseDescription((string) $latestRelease['body']);
} catch (Exception $e) {
    $log->error($e->getMessage());
}

$result['log'] = $app->getLoggerRecords();

echo json_encode($result, JSON_UNESCAPED_UNICODE);

/**
 * @param array<string, mixed> $release
 */
function getReleaseLink(string $install, array $release): string
{
    // По-умолчанию ссылка на релиз.
    $link = $release['html_url'];

    $assets = $release['assets'] ?? [];
    // Пробуем найти ссылку на конкретный zip.
    if (count($assets)) {
        if ($install === 'standalone') {
            foreach ($assets as $asset) {
                if (preg_match('/webtlo-win-.*\.zip/', $asset['name'])) {
                    return $asset['browser_download_url'];
                }
            }
        }
        if ($install === 'zip') {
            foreach ($assets as $asset) {
                if ($asset['name'] === 'webtlo.zip') {
                    return $asset['browser_download_url'];
                }
            }
        }
    }

    return $link;
}

function getReleaseDescription(string $desc): string
{
    // Ищем важное в описании релиза.
    preg_match('/(?<=### Список изменений)(.*)(?=---)/s', $desc, $matches);
    if (!empty($matches[0])) {
        $desc = trim($matches[0]);
    }

    // Возвращаем первые 1024 символа или около того.
    return mb_strlen($desc) > 1024 ? mb_substr($desc, 0, 1000) . '...' : $desc;
}
