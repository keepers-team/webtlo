<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(['src', 'public'])
;

$config = new PhpCsFixer\Config();

/**
 * Описание правил.
 *
 * @see https://cs.symfony.com/doc/ruleSets/index.html
 * @see https://cs.symfony.com/doc/rules/index.html
 */
$rules = [
    '@PHP8x1Migration' => true,

    '@PER-CS'     => true,
    '@PhpCsFixer' => true,

    '@PhpCsFixer:risky' => true,

    'native_constant_invocation' => false,
    'native_function_invocation' => false,

    'yoda_style' => ['equal' => false, 'identical' => false],

    'global_namespace_import' => ['import_classes' => true],
    'function_declaration'    => [
        'closure_fn_spacing'       => 'none',
        'closure_function_spacing' => 'none',
    ],

    'trailing_comma_in_multiline' => [
        'after_heredoc' => true,
        'elements'      => ['array_destructuring', 'arrays', 'match', 'parameters'],
    ],

    'phpdoc_to_comment' => false,

    'single_line_comment_style' => ['comment_types' => ['hash']],

    'ordered_types'      => ['null_adjustment' => 'always_first', 'sort_algorithm' => 'none'],
    'phpdoc_types_order' => ['null_adjustment' => 'always_first', 'sort_algorithm' => 'none'],

    'ordered_class_elements'   => false,
    'explicit_string_variable' => false,

    'blank_line_before_statement' => true,
    'type_declaration_spaces'     => false,

    'operator_linebreak' => ['only_booleans' => true],

    'binary_operator_spaces' => [
        'default'   => 'at_least_single_space',
        'operators' => [
            '='  => 'align',
            '=>' => 'align_single_space_minimal_by_scope',
        ],
    ],

    'echo_tag_syntax' => ['format' => 'short', 'shorten_simple_statements_only' => true],
    'concat_space' => ['spacing' => 'one'],
];

return $config
    ->setCacheFile('.cache/php-cs-fixer.cache')
    ->setRules($rules)
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
