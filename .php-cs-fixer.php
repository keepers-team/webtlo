<?php

$finder = PhpCsFixer\Finder::create()
    ->in(['src/back'])
;

$config = new PhpCsFixer\Config();
$rules  = [
    '@PSR12'          => true,
    '@PHP83Migration' => true,

    'no_empty_statement'     => true,
    'array_indentation'      => true,
    'standardize_not_equals' => true,

    'function_declaration' => [
        'closure_function_spacing' => 'none',
        'closure_fn_spacing'       => 'none',
    ],
];

return $config->setRules($rules)->setFinder($finder);
