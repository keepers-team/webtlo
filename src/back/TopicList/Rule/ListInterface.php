<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList\Rule;

use KeepersTeam\Webtlo\TopicList\Filter\Sort;
use KeepersTeam\Webtlo\TopicList\Topics;

interface ListInterface
{
    /** Получить список раздач по заданным условиям. */
    public function getTopics(array $filter, Sort $sort): Topics;
}