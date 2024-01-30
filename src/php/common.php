<?php

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Db;

include_once dirname(__FILE__) . '/../vendor/autoload.php';

include_once dirname(__FILE__) . '/classes/proxy.php';

App::init();
Db::create();

function get_settings($filename = ''): array
{
    return App::getSettings($filename);
}