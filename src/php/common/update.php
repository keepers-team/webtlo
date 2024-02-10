<?php

declare(strict_types=1);

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Legacy\Log;
use KeepersTeam\Webtlo\Timers;
use KeepersTeam\Webtlo\Update\ForumTree;
use KeepersTeam\Webtlo\Update\TopicsDetails;

$app = AppContainer::create('update.log');

Timers::start('full_update');

/** @var ForumTree $forumTree Обновляем дерево подразделов. */
$forumTree = $app->get(ForumTree::class);
$forumTree->update();

// обновляем списоки раздач в хранимых подразделах
include_once dirname(__FILE__) . '/update_subsections.php';

// обновляем список высокоприоритетных раздач
include_once dirname(__FILE__) . '/high_priority_topics.php';

// обновляем дополнительные сведения о раздачах (названия раздач)
/** @var TopicsDetails $detailsClass */
$detailsClass = $app->get(TopicsDetails::class);
$detailsClass->update();

// обновляем списки раздач в торрент-клиентах
include_once dirname(__FILE__) . '/tor_clients.php';

Log::append(sprintf('Обновление всех данных завершено за %s', Timers::getExecTime('full_update')));
