<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo;

use KeepersTeam\Webtlo\Config\AverageSeeds;
use KeepersTeam\Webtlo\Config\ConfigMigration;
use KeepersTeam\Webtlo\Infrastructure\Database\ConnectionInterface;
use KeepersTeam\Webtlo\Infrastructure\Database\SQLiteAdapter;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Предоставляет ключевые классы для работы приложения.
 */
final class AppServiceProvider extends AbstractServiceProvider
{
    public function provides(string $id): bool
    {
        $services = [
            ConnectionInterface::class,
            SQLiteAdapter::class,
            TIniFileEx::class,
            WebTLO::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        // Подключаем БД.
        $container->add(ConnectionInterface::class, static function() use ($container) {
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            /** @var AverageSeeds $average */
            $average = $container->get(AverageSeeds::class);

            return SQLiteAdapter::connect(logger: $logger, averageSeeds: $average);
        });

        // Обработчик ini-файла с конфигом.
        $container->add(TIniFileEx::class, static function() {
            $ini = new TIniFileEx();

            // Мигрируем, если есть что.
            (new ConfigMigration(ini: $ini))->run();

            return $ini;
        });

        // Подключаем описание версии WebTLO.
        $container->add(WebTLO::class, static fn() => WebTLO::getVersion());
    }
}
