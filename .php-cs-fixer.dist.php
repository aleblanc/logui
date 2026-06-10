<?php

// Note: `templates/` is intentionally NOT scanned — those are raw PHP view files that must not
// receive declare(strict_types) or @Symfony reformatting.
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/config', __DIR__.'/phpstan-rules'])
    ->exclude('fixtures');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'php_unit_method_casing' => ['case' => 'snake_case'],
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
