<?php

$finder = PhpCsFixer\Finder::create()
    ->in(['src/back'])
;

$config = new PhpCsFixer\Config();

$rules = [
    '@PER-CS'     => true,
    '@PhpCsFixer' => true,

    'global_namespace_import' => ['import_classes' => true],
    'function_declaration'    => [
        'closure_fn_spacing'       => 'none',
        'closure_function_spacing' => 'none',
    ],

    'phpdoc_to_comment' => false,

    'single_line_comment_style' => ['comment_types' => ['hash']],

    'ordered_types'      => ['null_adjustment' => 'always_first', 'sort_algorithm' => 'none'],
    'phpdoc_types_order' => ['null_adjustment' => 'always_first', 'sort_algorithm' => 'none'],

    'ordered_class_elements'   => false,
    'explicit_string_variable' => false,

    'blank_line_before_statement' => true,
    'type_declaration_spaces'     => false,

    'binary_operator_spaces' => [
        'default'   => 'at_least_single_space',
        'operators' => ['=' => 'align'],
    ],

    'concat_space' => ['spacing' => 'one'],
];

return $config
    ->setRules($rules)->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
;
