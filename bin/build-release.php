#!/usr/bin/env php
<?php

declare(strict_types=1);

use Maradigma\Support\PluginReleaseBuilder;

$rootDir = dirname(__DIR__);
$autoloadFile = $rootDir . '/includes/Autoload.php';
$pluginMainFile = $rootDir . '/maradigma.php';

if (!is_file($autoloadFile)) {
    fwrite(STDERR, "[ERROR] Autoload.php not found at: {$autoloadFile}" . PHP_EOL);
    exit(1);
}

require_once $autoloadFile;

if (class_exists(\Maradigma\Autoload::class)) {
    \Maradigma\Autoload::register($rootDir);
} else {
    fwrite(STDERR, "[ERROR] Maradigma\\Autoload class not found." . PHP_EOL);
    exit(1);
}

if (!class_exists(PluginReleaseBuilder::class)) {
    fwrite(STDERR, "[ERROR] PluginReleaseBuilder class not found." . PHP_EOL);
    exit(1);
}

try {
    $builder = new PluginReleaseBuilder($rootDir, $pluginMainFile);
    $result = $builder->build();

    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, "Release created successfully." . PHP_EOL);
    fwrite(STDOUT, "Version: " . $result['version'] . PHP_EOL);
    fwrite(STDOUT, "ZIP: " . $result['zip_path'] . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, PHP_EOL);
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . PHP_EOL);
    fwrite(STDERR, PHP_EOL);

    exit(1);
}