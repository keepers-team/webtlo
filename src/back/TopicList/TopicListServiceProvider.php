<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use KeepersTeam\Webtlo\Config\AverageSeeds;
use KeepersTeam\Webtlo\Config\FilterRules;
use KeepersTeam\Webtlo\Config\ForumConnect;
use KeepersTeam\Webtlo\Config\SubForums;
use KeepersTeam\Webtlo\Config\TorrentClients;
use KeepersTeam\Webtlo\Config\UserInfo;
use League\Container\ServiceProvider\AbstractServiceProvider;

final class TopicListServiceProvider extends AbstractServiceProvider
{
    public function provides(string $id): bool
    {
        $services = [
            ConfigFilter::class,
            Formatter::class,
        ];

        return in_array($id, $services, true);
    }

    public function register(): void
    {
        $container = $this->getContainer();

        $container->addShared(ConfigFilter::class, function() use ($container) {
            /** @var UserInfo $user */
            $user = $container->get(UserInfo::class);

            /** @var AverageSeeds $average */
            $average = $container->get(AverageSeeds::class);

            /** @var FilterRules $filterRules */
            $filterRules = $container->get(FilterRules::class);

            /** @var SubForums $subForums */
            $subForums = $container->get(SubForums::class);

            $notHidden = array_filter(
                $subForums->params,
                static fn($subForum) => !$subForum->hideTopics
            );

            return new ConfigFilter(
                userId              : $user->userId,
                excludeSelf         : $filterRules->excludeSelf,
                enableAverageHistory: $average->enableHistory,
                notHiddenSubForums  : array_column($notHidden, 'id')
            );
        });

        $container->addShared(Formatter::class, function() use ($container) {
            /** @var ForumConnect $forum */
            $forum = $container->get(ForumConnect::class);

            /** @var TorrentClients $clients */
            $clients = $container->get(TorrentClients::class);

            return new Formatter(
                clients : $clients->getClientsNames(),
                forumUrl: $forum->buildUrl(),
            );
        });
    }
}
