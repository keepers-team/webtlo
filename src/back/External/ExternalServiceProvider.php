<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External;

use KeepersTeam\Webtlo\External\Construct\ApiForumConstructor;
use KeepersTeam\Webtlo\External\Construct\ApiReportConstructor;
use KeepersTeam\Webtlo\External\Construct\ForumConstructor;
use League\Container\ServiceProvider\AbstractServiceProvider;

final class ExternalServiceProvider extends AbstractServiceProvider
{
    public function provides(string $id): bool
    {
        $services = [
            ForumClient::class,
            ApiClient::class,
            ApiReportClient::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        // Добавляем клиент для работы с Форумом.
        $container->addShared(ForumClient::class, function() use ($container) {
            /** @var ForumConstructor $helper */
            $helper = $container->get(ForumConstructor::class);

            return $helper->createRequestClient();
        });

        // Добавляем клиент для работы с API форума.
        $container->add(ApiClient::class, function() use ($container) {
            /** @var ApiForumConstructor $helper */
            $helper = $container->get(ApiForumConstructor::class);

            return $helper->createRequestClient();
        });

        // Добавляем клиент для работы с API отчётов.
        $container->add(ApiReportClient::class, function() use ($container) {
            /** @var ApiReportConstructor $helper */
            $helper = $container->get(ApiReportConstructor::class);

            return $helper->createRequestClient();
        });
    }
}
