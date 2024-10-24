<?php

$finder = PhpCsFixer\Finder::create()
    ->in(['src/back'])
;

$config = new PhpCsFixer\Config();
$rules  = [
    '@PER-CS'         => true,
    '@PHP83Migration' => true,

    'no_empty_statement'     => true,
    'standardize_not_equals' => true,

    'function_declaration' => [
        'closure_fn_spacing'       => 'none',
        'closure_function_spacing' => 'none',
    ],
];

return $config
    ->setRules($rules)->setFinder($finder)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
;
