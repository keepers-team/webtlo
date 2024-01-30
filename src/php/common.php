<?php

use KeepersTeam\Webtlo\Settings;
use KeepersTeam\Webtlo\TIniFileEx;

include_once dirname(__FILE__) . '/../vendor/autoload.php';

include_once dirname(__FILE__) . '/classes/date.php';
include_once dirname(__FILE__) . '/classes/log.php';
include_once dirname(__FILE__) . '/classes/db.php';
include_once dirname(__FILE__) . '/classes/proxy.php';

Db::create();

function get_settings($filename = ''): array
{
    // TODO container auto-wire.
    $ini      = new TIniFileEx($filename);
    $settings = new Settings($ini);

    return $settings->populate();
}