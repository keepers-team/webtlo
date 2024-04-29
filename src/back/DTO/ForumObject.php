<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\DTO;

final class ForumObject
{
    public function __construct(
        public int     $id,
        public string  $name,
        public int     $quantity,
        public int     $size,
        public ?int    $topic_id,
        public ?int    $author_id,
        public ?string $author_name,
        public ?int    $author_post_id,
        /** @var ?int[] */
        public ?array  $post_ids
    ) {
    }
}
