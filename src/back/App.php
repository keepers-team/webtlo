<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use KeepersTeam\Webtlo\Config\Credentials;
use KeepersTeam\Webtlo\Config\ForumCredentials;
use KeepersTeam\Webtlo\Config\Proxy;
use KeepersTeam\Webtlo\Config\ReportSend;
use KeepersTeam\Webtlo\Config\TopicControl;
use KeepersTeam\Webtlo\External\ApiClient;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\ForumClient;
use KeepersTeam\Webtlo\Static\AppLogger;
use KeepersTeam\Webtlo\Storage\CloneServiceProvider;
use League\Container\Container;
use League\Container\ReflectionContainer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class App
{
    private static bool $initialized = false;

    private static ?self $appContainer = null;

    private function __construct(public readonly Container $container) {}

    public static function init(): void
    {
        if (!self::$initialized) {
            // Проставляем часовой пояс.
            self::setDefaultTimeZone();

            self::$initialized = true;
        }
    }

    /** Создаём di-контейнер. */
    public static function create(?string $logFile = null): self
    {
        // Если контейнер уже создан, новый не создаём.
        if (self::$appContainer !== null) {
            return self::$appContainer;
        }

        // Указываем часовой пояс, в т.ч. для корректной записи логов.
        self::init();

        // Создаём di-контейнер и включаем auto wiring.
        $container = new Container();
        $container->defaultToShared();
        $container->delegate(new ReflectionContainer(true));

        // Добавляем создание таблиц-клонов.
        $container->addServiceProvider(new CloneServiceProvider());

        // Подключаем описание версии WebTLO.
        $container->add(WebTLO::class, fn() => WebTLO::loadFromFile());

        // Подключаем файл конфига, 'config.ini' по-умолчанию.
        $container->add(Settings::class, fn() => new Settings(
            ini: new TIniFileEx(),
            db : $container->get(DB::class),
        ));
        $container->add('config', fn() => $container->get(Settings::class)->populate());

        // Добавляем интерфейс для записи логов.
        $container->add(LoggerInterface::class, function() use ($container, $logFile) {
            $config = $container->get('config');
            $level  = AppLogger::getLogLevel($config['log_level'] ?? '');

            return AppLogger::create($logFile, $level);
        });

        // Получение данных авторизации
        $container->add(Credentials::class, fn() => Credentials::fromLegacy($container->get('config')));
        $container->add(ApiCredentials::class, fn() => ApiCredentials::fromLegacy($container->get('config')));
        $container->add(ForumCredentials::class, fn() => ForumCredentials::fromLegacy($container->get('config')));

        // Опции получения и отправки отчётов.
        $container->add(ReportSend::class, fn() => ReportSend::getReportSend($container->get('config')));

        // Опции регулировки раздач.
        $container->add(TopicControl::class, fn() => TopicControl::getTopicControl($container->get('config')));

        // Получаем настройки прокси.
        $container->add(Proxy::class, fn() => Proxy::fromLegacy($container->get('config')));

        // Добавляем клиент для работы с Форумом.
        $container->add(ForumClient::class, function() use ($container) {
            $logger = $container->get(LoggerInterface::class);

            $settings = $container->get(Settings::class);
            $proxy    = $container->get(Proxy::class);
            $cred     = $container->get(ForumCredentials::class);

            return ForumClient::createFromLegacy($settings, $cred, $logger, $proxy);
        });

        // Добавляем клиент для работы с API.
        $container->add(ApiClient::class, function() use ($container) {
            $logger = $container->get(LoggerInterface::class);

            $proxy  = $container->get(Proxy::class);
            $config = $container->get('config');

            return new ApiClient(
                ApiClient::getDefaultParams($config),
                ApiClient::apiClientFromLegacy($config, $logger, $proxy),
                $logger,
                $config['api_request_threshold'],
            );
        });

        // Добавляем клиент для работы с API отчётов.
        $container->add(ApiReportClient::class, function() use ($container) {
            $logger = $container->get(LoggerInterface::class);

            $proxy  = $container->get(Proxy::class);
            $config = $container->get('config');

            $cred = ApiReportClient::apiCredentials($config);

            return new ApiReportClient(
                ApiReportClient::apiClientFromLegacy($config, $cred, $logger, $proxy),
                $cred,
                $logger
            );
        });

        // Подключаем БД.
        $container->add(DB::class, fn() => DB::create());

        return self::$appContainer = new self($container);
    }

    public function get(string $id): mixed
    {
        try {
            return $this->container->get($id);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getLegacyConfig(): array
    {
        return $this->get('config');
    }

    public function getDataBase(): DB
    {
        return $this->get(DB::class);
    }

    public function getForumClient(): ForumClient
    {
        return $this->get(ForumClient::class);
    }

    public function getApiClient(): ApiClient
    {
        return $this->get(ApiClient::class);
    }

    public function getApiReportClient(): ApiReportClient
    {
        return $this->get(ApiReportClient::class);
    }

    public function getSettings(): Settings
    {
        return $this->get(Settings::class);
    }

    public function getLogger(): LoggerInterface
    {
        return $this->get(LoggerInterface::class);
    }

    public function getClientFactory(): ClientFactory
    {
        return $this->get(ClientFactory::class);
    }

    /**
     * Установить часовой пояс по-умолчанию.
     */
    private static function setDefaultTimeZone(): void
    {
        if (!ini_get('date.timezone')) {
            date_default_timezone_set(getenv('TZ') ?: 'Europe/Moscow');
        }
    }
}
