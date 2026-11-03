<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Clients\Data;

use LogicException;

/**
 * Попытка выполнить неподдерживаемое конкретным торрент-клиентом действие.
 */
final class UnsupportedOperationException extends LogicException {}
