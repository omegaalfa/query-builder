<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->exclude(['vendor', 'coverage']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
