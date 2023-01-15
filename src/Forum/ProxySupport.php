<?php

namespace KeepersTeam\Webtlo\Forum;

use KeepersTeam\Webtlo\Config\Proxy;
use Psr\Log\LoggerInterface;

trait ProxySupport
{
    protected static function getProxyConfig(LoggerInterface $logger, ?Proxy $proxy): array
    {
        $options = [];
        if (null !== $proxy && $proxy->enabled) {
            $needsAuth = null !== $proxy->credentials;
            $curlOptions = [CURLOPT_PROXYTYPE => $proxy->type->value];
            $logger->info(
                'Used proxy',
                [
                    'hostname' => $proxy->hostname,
                    'port' => $proxy->port,
                    'type' => $proxy->type->name,
                    'authenticated' => $needsAuth,
                ]
            );
            if ($needsAuth) {
                $curlOptions[CURLOPT_PROXYUSERPWD] = sprintf(
                    "%s:%s",
                    $proxy->credentials->username,
                    $proxy->credentials->password
                );
            }

            $options['proxy'] = sprintf("%s:%d", $proxy->hostname, $proxy->port);
            $options['curl'] = $curlOptions;
        }
        return $options;
    }
}
