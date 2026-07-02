<?php

if (file_exists(__DIR__ . '/.php-cs-fixer.cache')) {
    unlink(__DIR__ . '/.php-cs-fixer.cache');
}

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__);

$config = new PhpCsFixer\Config();
return $config->setRules([
    '@PSR12' => true,
    'array_syntax' => ['syntax' => 'short'],
])
    ->setFinder($finder);