<?php

use KeepersTeam\Webtlo\App;
use KeepersTeam\Webtlo\Legacy\Db;

include_once dirname(__FILE__) . '/../vendor/autoload.php';

App::init();
Db::create();

function get_settings($filename = ''): array
{
    return App::getSettings($filename);
}