<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Static\AppLogger;
use League\Container\Container;
use League\Container\ReflectionContainer;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class AppContainer
{
    private static ?self $appContainer = null;

    private function __construct(public readonly Container $container)
    {
    }

    /** Создаём di-контейнер. */
    public static function create(?string $logFile = null): self
    {
        // Если контейнер уже создан, новый не создаём.
        if (null !== self::$appContainer) {
            return self::$appContainer;
        }

        // Указываем временную зону, в т.ч. для корректной записи логов.
        App::init();

        // Создаём di-контейнер и включаем auto wiring.
        $container = new Container();
        $container->defaultToShared();
        $container->delegate(new ReflectionContainer(true));

        // Подключаем описание версии WebTLO.
        $container->add(WebTLO::class, fn() => WebTLO::loadFromFile());

        // Подключаем файл конфига, 'config.ini' по-умолчанию.
        $container->add('config', function() {
            return (new Settings(new TIniFileEx()))->populate();
        });

        // Добавляем логгер.
        $container->add(LoggerInterface::class, fn() => AppLogger::create($logFile));

        // Подключаем БД.
        $container->add(DB::class, fn() => DB::create());

        return self::$appContainer = new self($container);
    }

    public function get(string $id)
    {
        try {
            return $this->container->get($id);
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), $e->getCode());
        }
    }

    public function getLegacyConfig(): array
    {
        return $this->get('config');
    }
    public function getSettings(): Settings
    {
        return $this->get(Settings::class);
    }

    public function getLogger(): LoggerInterface
    {
        return $this->get(LoggerInterface::class);
    }
}
