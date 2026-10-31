<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

/*
 * Coding style shared by the three repositories: PSR-12 plus the rules below, which keep the code bases uniform.
 *
 * No rule may rewrite code to syntax newer than PHP 7.2, the lowest supported version (so no @PHPxxMigration sets, no
 * modernize_strpos, no trailing commas in calls or parameter lists). Only src/, tests/, public/ and this tooling are
 * checked: files of the previous major version outside them stay as they are until they are replaced.
 */
$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/public'])
    ->append([__FILE__, __DIR__ . '/tools/require-tool.php']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => ['statements' => ['return', 'throw']],
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'linebreak_after_opening_tag' => true,
        'native_function_casing' => true,
        'no_empty_phpdoc' => true,
        'no_extra_blank_lines' => true,
        'no_trailing_comma_in_singleline' => true,
        'no_unused_imports' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line' => true,
        'nullable_type_declaration_for_default_null_value' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_indent' => true,
        'phpdoc_scalar' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
        'single_quote' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'whitespace_after_comma_in_array' => true,
    ]);
