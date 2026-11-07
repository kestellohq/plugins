<?php

declare(strict_types=1);

/*
 * The same code style as Kestello itself: PER Coding Style 2.0, plus a few rules that keep
 * diffs small. Run: sh tools/check.sh (checks) or sh tools/check.sh --fix (formats).
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->ignoreVCSIgnored(true)
    ->exclude(['kestello', 'node_modules', 'dist'])
    ->notPath(['assets/build'])
    ->name(['*.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules([
        '@PER-CS2x0' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'single_quote' => true,
        'no_trailing_whitespace_in_string' => false,
    ]);
