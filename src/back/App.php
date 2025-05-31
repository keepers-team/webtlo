<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Clients\ClientFactory;
use KeepersTeam\Webtlo\Config\Automation;
use KeepersTeam\Webtlo\Config\ConfigServiceProvider;
use KeepersTeam\Webtlo\Config\Other as ConfigOther;
use KeepersTeam\Webtlo\External\ApiForumClient;
use KeepersTeam\Webtlo\External\ApiReportClient;
use KeepersTeam\Webtlo\External\ExternalServiceProvider;
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

        // Основные классы для работы.
        $container->addServiceProvider(new AppServiceProvider());
        // Добавляем обработчик классов конфига.
        $container->addServiceProvider(new ConfigServiceProvider());
        // Добавляем создание таблиц-клонов.
        $container->addServiceProvider(new CloneServiceProvider());
        // Добавляем подключение к внешним ресурсам.
        $container->addServiceProvider(new ExternalServiceProvider());

        // Подключаем файл конфига, 'config.ini' по-умолчанию.
        $container->add(Settings::class, fn() => new Settings(
            ini: new TIniFileEx(),
            db : $container->get(DB::class),
        ));
        $container->add('config', fn() => $container->get(Settings::class)->populate());

        // Добавляем интерфейс для записи логов.
        $container->add(LoggerInterface::class, function() use ($container, $logFile) {
            /** @var ConfigOther $params */
            $params = $container->get(ConfigOther::class);

            $level = AppLogger::getLogLevel(logLevel: $params->logLevel);

            return AppLogger::create(logFile: $logFile, logLevel: $level);
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

    public function getAutomation(): Automation
    {
        return $this->get(Automation::class);
    }

    public function getForumClient(): ForumClient
    {
        return $this->get(ForumClient::class);
    }

    public function getApiForumClient(): ApiForumClient
    {
        return $this->get(ApiForumClient::class);
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
