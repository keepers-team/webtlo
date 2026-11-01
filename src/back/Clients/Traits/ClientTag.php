<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

trait ClientTag
{
    public function getClientTypeName(): string
    {
        return $this->options->type->name;
    }

    public function getClientTag(): string
    {
        return $this->options->tag;
    }
}
