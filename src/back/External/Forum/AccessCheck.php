<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Forum;

/**
 * Ошибки при проверке доступа к форуму.
 */
enum AccessCheck: string
{
    case NOT_AUTHORIZED   = 'Нет доступа на форум. Пройдите авторизацию в настройках.';
    case USER_CANDIDATE   = 'Нет доступа в рабочий подфорум хранителей. Если вы Кандидат, то ожидайте включения в основную группу.';
    case VERSION_OUTDATED = 'Отправка отчётов для текущей версии web-TLO заблокирована. Установите актуальную версию web-TLO для корректной отправки отчётов.';
}
