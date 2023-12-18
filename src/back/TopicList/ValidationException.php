<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use Exception;
use Throwable;

final class ValidationException extends Exception
{
    private string $class = '';

    public function __construct(string $message, string $class, int $code = 0, Throwable $previous = null)
    {
        $this->class = $class;

        parent::__construct($message, $code, $previous);
    }

    public function getClass(): string
    {
        return $this->class;
    }
}