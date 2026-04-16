<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$config = new Nextcloud\CodingStandard\Config();
$config->getFinder()
    ->in(__DIR__ . '/lib')
    ->in(__DIR__ . '/tests')
    ->name('*.php');

return $config;
