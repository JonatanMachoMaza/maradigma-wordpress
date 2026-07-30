<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Composer dependencies are missing. Run `composer install`.\n");
    exit(1);
}

require_once $autoload;

if (!defined('MARADIGMA_PLUGIN_VERSION')) {
    define('MARADIGMA_PLUGIN_VERSION', 'test');
}
