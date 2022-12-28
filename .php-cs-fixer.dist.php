<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('phpQuery')
    ->notPath(['phpQuery.php', 'bencode.php', 'torrenteditor.php'])
    ->in(['src', 'src/legacy/php']);

$config = new PhpCsFixer\Config();
return $config->setRules([
    '@PSR12' => true,
//    'strict_param' => true,
    'array_syntax' => ['syntax' => 'short'],
])->setFinder($finder);
