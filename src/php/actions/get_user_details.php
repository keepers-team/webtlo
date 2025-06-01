<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Config\ForumCredentials;
use KeepersTeam\Webtlo\External\Construct\ForumConstructor;

$result = [
    'bt_key'       => '',
    'api_key'      => '',
    'user_id'      => '',
    'user_session' => '',
    'captcha'      => '',
    'captcha_path' => '',
];

// Подключаем контейнер.
$app = App::create();
$log = $app->getLogger();

try {
    parse_str($_POST['cfg'], $config);

    if (
        empty($config['tracker_username'])
        || empty($config['tracker_password'])
    ) {
        throw new Exception();
    }

    // Логин и пароль для авторизации на форуме.
    $username = is_array($config['tracker_username'])
        ? implode('', $config['tracker_username'])
        : (string) $config['tracker_username'];
    $password = is_array($config['tracker_password'])
        ? implode('', $config['tracker_password'])
        : (string) $config['tracker_password'];

    $forumAuth = ForumCredentials::fromFrontProperties(login: $username, password: $password);

    // Заполненный пользователем код CAPTCHA.
    $captchaFields = null;
    if (!empty($_POST['cap_code']) && !empty($_POST['cap_fields'])) {
        $captchaCode = (string) $_POST['cap_code'];

        $fields = explode(',', (string) $_POST['cap_fields']);

        $captchaFields = [
            $fields[0] => $fields[1],
            $fields[2] => $captchaCode,
        ];

        unset($captchaCode, $fields);
    }

    /** @var ForumConstructor $helper */
    $helper = $app->get(ForumConstructor::class);
    $helper->setForumCredentials(credentials: $forumAuth);

    // Подключаемся к форуму.
    $forumClient = $helper->createRequestClient();

    // Пробуем авторизоваться.
    $captchaRequest = $forumClient->manualLogin(captcha: $captchaFields);
    if ($captchaRequest === null) {
        // Авторизация выполнена успешно.
        $result['user_session'] = $forumClient->getUpdatedCookie();

        // Получаем хранительские ключи для доступа к API.
        $apiCred = $forumClient->searchApiCredentials();
        if ($apiCred !== null) {
            $result['bt_key']  = $apiCred->btKey;
            $result['api_key'] = $apiCred->apiKey;
            // Принудительный перевод в строку, т.к. jQuery считает int пустым объектом.
            $result['user_id'] = (string) $apiCred->userId;
        }
    } else {
        // Получили CAPTCHA с ошибкой авторизации.
        $log->warning($captchaRequest->message);

        $result['captcha']      = $captchaRequest->codes;
        $result['captcha_path'] = $forumClient->fetchCaptchaImage(imageLink: $captchaRequest->image);
    }
} catch (Exception $e) {
    $log->error($e->getMessage());
} finally {
    $log->info('-- DONE --');
}

$result['log'] = $app->getLoggerRecords();

echo json_encode($result, JSON_UNESCAPED_UNICODE);
