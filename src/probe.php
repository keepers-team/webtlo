<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Module\ProbeChecker;

Header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");

AppContainer::init();

$proxies = [
    null,
    ['gateway.keeps.cyou', 2081],
];

$urls = [
    'forum'  => [
        'rutracker.org',
        'rutracker.net',
        'rutracker.nl',
    ],
    'api'    => [
        'api.rutracker.cc',
    ],
    'report' => [
        'rep.rutracker.cc',
    ],
];


$checker = new ProbeChecker($urls, $proxies);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <title>webTLO configuration checker</title>
    <style>
        body {
            color: var(--text);
            background-color: var(--bg);
            font-size: 1.15rem;
            line-height: 1.5;
            display: grid;
            grid-template-columns: 1fr min(45rem, 90%) 1fr;
            margin: 0;
        }

        body > * {
            grid-column: 2;
        }

        h2 {
            font-size: 2rem;
            margin-top: 3rem;
            line-height: 1.1;
        }

        textarea {
            font-size: 1rem;
            padding: 1rem 1.4rem;
            max-width: 100%;
            overflow: auto;
            color: var(--preformatted);

            font-family: var(--mono-font), monospace;

            background-color: var(--accent-bg);
            border: 1px solid var(--border);
            border-radius: var(--standard-border-radius);
            margin-bottom: 1rem;
        }

        ::backdrop, :root {
            --sans-font: -apple-system, BlinkMacSystemFont, "Avenir Next", Avenir, "Nimbus Sans L", Roboto, "Noto Sans", "Segoe UI", Arial, Helvetica, "Helvetica Neue", sans-serif;
            --mono-font: Consolas, Menlo, Monaco, "Andale Mono", "Ubuntu Mono", monospace;

            --standard-border-radius: 5px;
            --bg: #212121;
            --accent-bg: #2b2b2b;
            --text: #dcdcdc;
            --text-light: #ababab;
            --accent: #ffb300;
            --code: #f06292;
            --preformatted: #ccc;
            --disabled: #111;
            --border: #898EA4;
        }
    </style>
</head>
<body>
<h2>webTLO configuration checker</h2>
<label>
<textarea rows="35" cols="120" spellcheck="false">
<?= $checker->printProbe(); ?>
</textarea>
</label>
</body>
</html>
