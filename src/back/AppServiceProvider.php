<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Config\ConfigMigration;
use League\Container\ServiceProvider\AbstractServiceProvider;

/**
 * Предоставляет ключевые классы для работы приложения.
 */
final class AppServiceProvider extends AbstractServiceProvider
{
    public function provides(string $id): bool
    {
        $services = [
            DB::class,
            TIniFileEx::class,
            WebTLO::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        // Подключаем БД.
        $container->add(DB::class, fn() => DB::create());

        // Обработчик ini-файла с конфигом.
        $container->addShared(TIniFileEx::class, function() {
            $ini = new TIniFileEx();

            // Мигрируем, если есть что.
            (new ConfigMigration($ini))->run();

            return $ini;
        });

        // Подключаем описание версии WebTLO.
        $container->add(WebTLO::class, fn() => WebTLO::loadFromFile());
    }
}
