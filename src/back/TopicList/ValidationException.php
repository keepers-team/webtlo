<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\TopicList;

use Exception;
use Throwable;

final class ValidationException extends Exception
{
    public function __construct(string $message, private readonly string $class = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getClass(): string
    {
        return $this->class;
    }
}
