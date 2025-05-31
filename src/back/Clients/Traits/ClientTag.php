<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

trait ClientTag
{
    public function getClientTag(): string
    {
        return $this->options->tag;
    }
}
