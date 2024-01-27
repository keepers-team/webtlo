<?php

use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Timers;

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/api.php';

Timers::start('full_update');

// обновляем списоки раздач в хранимых подразделах
include_once dirname(__FILE__) . '/update_subsections.php';

// обновляем список высокоприоритетных раздач
include_once dirname(__FILE__) . '/high_priority_topics.php';

// обновляем дополнительные сведения о раздачах (названия раздач)
include_once dirname(__FILE__) . '/update_details.php';

// обновляем списки раздач в торрент-клиентах
include_once dirname(__FILE__) . '/tor_clients.php';

Log::append(sprintf('Обновление всех данных завершено за %s', Timers::getExecTime('full_update')));