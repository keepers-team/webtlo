<?php

include_once dirname(__FILE__) . '/../../vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Update\ForumTree;

$app = AppContainer::create();

$forumTree = $app->get(ForumTree::class);

$forumTree->update();
