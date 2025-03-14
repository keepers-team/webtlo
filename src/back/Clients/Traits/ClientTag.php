<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Traits;

trait ClientTag
{
    public function getClientTag(): string
    {
        $tag = $this->options->type->value;

        $extra = $this->options->extra;
        if (count($extra) > 0) {
            if (!empty($extra['comment'])) {
                $tag = $extra['comment'];
            }

            if (!empty($extra['id'])) {
                $tag .= sprintf('(%s)', $extra['id']);
            }
        }

        return $tag;
    }
}
