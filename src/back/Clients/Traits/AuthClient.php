<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

use KeepersTeam\Webtlo\Config\TorrentClientOptions;

trait AuthClient
{
    private bool $authenticated = false;

    public function isOnline(): bool
    {
        return $this->authenticated ?? false;
    }

    protected function getClientBase(TorrentClientOptions $options, string $api = ''): string
    {
        return sprintf(
            '%s://%s:%s/%s',
            $options->secure ? 'https' : 'http',
            $options->host,
            $options->port,
            $api
        );
    }
}
