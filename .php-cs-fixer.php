<?php
declare(strict_types=1);
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/build');
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                          => true,
        'declare_strict_types'            => true,
        'array_syntax'                    => ['syntax' => 'short'],
        'no_trailing_comma_in_singleline' => true,
        // KPHP compatibility: no trailing commas in multiline calls/arrays
        'trailing_comma_in_multiline'     => ['elements' => []],
        'ordered_imports'                 => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'               => true,
        'blank_line_after_opening_tag'    => false,
    ])
    ->setFinder($finder);
