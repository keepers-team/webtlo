<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Forum;

enum AccessCheck: string
{
    case NOT_AUTHORIZED   = 'Error: Нет доступа на форум. Пройдите авторизацию в настройках.';
    case USER_CANDIDATE   = 'Error: Нет доступа в рабочий подфорум хранителей. Если вы Кандидат, то ожидайте включения в основную группу.';
    case VERSION_OUTDATED = 'Error: Отправка отчётов для текущей версии web-TLO заблокирована. Установите актуальную версию web-TLO для корректной отправки отчётов';
}