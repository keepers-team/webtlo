<?php

$finder = PhpCsFixer\Finder::create()
    ->in(['src/back'])
;

$config = new PhpCsFixer\Config();
return $config->setRules([
    '@PSR12'          => true,
    '@PHP82Migration' => true,

    'no_empty_statement'       => true,
    'single_blank_line_at_eof' => false,
    'array_indentation'        => true,
    'standardize_not_equals'   => true,

    'function_declaration' => [
        'closure_function_spacing' => 'none',
        'closure_fn_spacing'       => 'none',
    ],
])->setFinder($finder);
