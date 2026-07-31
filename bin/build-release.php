#!/usr/bin/env php
<?php
/**
 * Build a distributable ZIP archive for the Maradigma WordPress plugin.
 *
 * This PHP 8.3-compatible CLI entry point bootstraps the plugin autoloader and
 * delegates release validation, staging, and archive creation to
 * PluginReleaseBuilder. It accepts no command-line options.
 *
 * The builder writes the generated archive to the plugin's release directory
 * and may replace an existing archive for the same plugin version.
 *
 * Exit codes:
 * - 0: The release archive was built and validated successfully.
 * - 1: Bootstrap, validation, staging, or archive creation failed.
 *
 * @package Maradigma
 * @internal
 */

declare(strict_types=1);

use Maradigma\Support\PluginReleaseBuilder;

/** @var non-falsy-string $rootDir Absolute path to the plugin root directory. */
$rootDir = dirname(__DIR__);

/** @var non-falsy-string $autoloadFile Absolute path to the internal autoloader. */
$autoloadFile = $rootDir . '/includes/Autoload.php';

/** @var non-falsy-string $pluginMainFile Absolute path to the main plugin file. */
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

    /**
     * Metadata for the generated and validated release archive.
     *
     * @var array{
     *     version: string,
     *     zip_path: string,
     *     zip_filename: string,
     *     release_dir: string,
     *     release_slug: string
     * } $result
     */
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
