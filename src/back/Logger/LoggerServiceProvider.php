<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Logger;

use KeepersTeam\Webtlo\Config\Other as ConfigOther;
use League\Container\ServiceProvider\AbstractServiceProvider;
use Psr\Log\LoggerInterface;

final class LoggerServiceProvider extends AbstractServiceProvider
{
    public function __construct(
        private readonly ?string $logFile = null,
    ) {}

    public function provides(string $id): bool
    {
        return $id === LoggerInterface::class;
    }

    public function register(): void
    {
        $container = $this->getContainer();

        $container->addShared(LoggerInterface::class, function() use ($container) {
            /** @var ConfigOther $params */
            $params = $container->get(ConfigOther::class);

            $level = LoggerConstructor::getLogLevel(level: $params->logLevel);

            return LoggerConstructor::create(logFile: $this->logFile, level: $level);
        });
    }
}
