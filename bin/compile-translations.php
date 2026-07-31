<?php
/**
 * Compile Maradigma gettext PO catalogs into binary MO catalogs.
 *
 * This PHP 8.3-compatible CLI entry point can compile either one PO catalog or
 * every PO catalog in a configured languages directory. Relative paths are
 * resolved from the plugin root, and an existing destination MO file may be
 * overwritten.
 *
 * Exit codes:
 * - 0: Help was displayed, or every requested catalog compiled successfully.
 * - 1: Invocation, bootstrap, compilation, or output validation failed.
 *
 * @package Maradigma
 * @internal
 */

declare(strict_types=1);

use Maradigma\Autoload;
use Maradigma\Support\PoToMoCompiler;

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

// Refuse direct web execution even when WordPress has already defined ABSPATH.
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "Forbidden\n";
    exit(1);
}

$root = realpath(__DIR__ . '/../');
if ($root === false) {
    fwrite(STDERR, "ERROR: Cannot resolve plugin root.\n");
    exit(1);
}

// Define only the constants required by the plugin's internal autoloader.
if (!defined('MARADIGMA_PLUGIN_DIR')) {
    define('MARADIGMA_PLUGIN_DIR', $root . DIRECTORY_SEPARATOR);
}
if (!defined('MARADIGMA_INCLUDES_DIR')) {
    define('MARADIGMA_INCLUDES_DIR', MARADIGMA_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR);
}

// Load Composer first when dependencies have been installed.
$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

// Bootstrap the plugin's internal PSR-4-compatible autoloader.
$pluginAutoload = MARADIGMA_INCLUDES_DIR . 'Autoload.php';
if (!is_file($pluginAutoload)) {
    fwrite(STDERR, "ERROR: Plugin autoload not found at: {$pluginAutoload}\n");
    exit(1);
}
require_once $pluginAutoload;

if (!class_exists(Autoload::class)) {
    fwrite(STDERR, "ERROR: Maradigma\\Autoload not found after requiring Autoload.php\n");
    exit(1);
}
Autoload::register(MARADIGMA_PLUGIN_DIR);

if (!class_exists(PoToMoCompiler::class)) {
    fwrite(STDERR, "ERROR: Class Maradigma\\Support\\PoToMoCompiler not found. Check file path + namespace.\n");
    exit(1);
}

/** @var list<string> $argv Command-line arguments, including the script name. */
$argv = $_SERVER['argv'] ?? [];
$argc = $_SERVER['argc'] ?? 0;

/**
 * Write command usage instructions to standard output.
 */
function usage(): void
{
    $lines = [
        'Maradigma PO-to-MO compiler (CLI)',
        '',
        'Usage:',
        '  php bin/compile-translations.php --all',
        '  php bin/compile-translations.php --po=languages/maradigma-es_ES.po',
        '  php bin/compile-translations.php --po=... --mo=...',
        '',
        'Options:',
        '  --all                 Compile all *.po files in the languages directory',
        '  --po=PATH             PO path, absolute or relative to the plugin root',
        '  --mo=PATH             Optional MO output path, absolute or relative',
        '  --languages-dir=PATH  Languages directory (default: languages)',
        '  --help                Show this help',
        '',
    ];

    fwrite(STDOUT, implode(PHP_EOL, $lines) . PHP_EOL);
}

if ($argc <= 1 || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    usage();
    exit(0);
}

/**
 * Normalized compiler arguments.
 *
 * @var array{
 *     all: bool,
 *     po: string|null,
 *     mo: string|null,
 *     languages_dir: string
 * } $args
 */
$args = [
    'all'           => in_array('--all', $argv, true),
    'po'            => null,
    'mo'            => null,
    'languages_dir' => 'languages',
];

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--po=')) {
        $args['po'] = substr($argument, 5);
    } elseif (str_starts_with($argument, '--mo=')) {
        $args['mo'] = substr($argument, 5);
    } elseif (str_starts_with($argument, '--languages-dir=')) {
        $args['languages_dir'] = substr($argument, 16);
    }
}

/**
 * Resolve a user-supplied path without requiring the destination to exist.
 *
 * Absolute Windows, UNC, and Unix paths are returned unchanged. Relative paths
 * are anchored to the plugin root.
 *
 * @param string $path Absolute or plugin-root-relative path.
 *
 * @return string Resolved path, or an empty string for empty input.
 */
$resolvePath = static function (string $path) use ($root): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (
        preg_match('~^[A-Za-z]:\\\\~', $path) === 1
        || str_starts_with($path, '\\\\')
        || str_starts_with($path, '/')
    ) {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
};

try {
    if ($args['all'] === true) {
        $langDir = $resolvePath($args['languages_dir']);
        if (!is_dir($langDir)) {
            throw new RuntimeException("Languages dir not found: {$langDir}");
        }

        $files = glob($langDir . DIRECTORY_SEPARATOR . '*.po');
        if ($files === false || empty($files)) {
            throw new RuntimeException("No .po files found in: {$langDir}");
        }
        /** @var list<string> $files */

        echo "Plugin root: {$root}\n";
        echo "Languages dir: {$langDir}\n\n";

        $ok = 0;
        $fail = 0;

        foreach ($files as $poPath) {
            $poPath = (string) $poPath;
            $moPath = preg_replace('~\.po$~i', '.mo', $poPath) ?: ($poPath . '.mo');

            echo "Compiling:\n  PO: {$poPath}\n  MO: {$moPath}\n";

            PoToMoCompiler::compile($poPath, $moPath);

            clearstatcache(true, $moPath);
            $size = is_file($moPath) ? (int) filesize($moPath) : 0;

            if ($size > 0) {
                echo "  [OK] {$size} bytes\n\n";
                $ok++;
            } else {
                echo "  [FAILED] Empty output\n\n";
                $fail++;
            }
        }

        echo "Done. OK={$ok}, FAILED={$fail}\n";
        exit($fail > 0 ? 1 : 0);
    }

    if (!is_string($args['po']) || trim($args['po']) === '') {
        throw new RuntimeException('Missing --po=PATH (or use --all).');
    }

    $poPath = $resolvePath($args['po']);
    if (!is_file($poPath)) {
        throw new RuntimeException("PO not found: {$poPath}");
    }

    $moPath = '';
    if (is_string($args['mo']) && trim($args['mo']) !== '') {
        $moPath = $resolvePath($args['mo']);
    } else {
        $moPath = preg_replace('~\.po$~i', '.mo', $poPath) ?: ($poPath . '.mo');
    }

    echo "Compiling:\n  PO: {$poPath}\n  MO: {$moPath}\n";

    PoToMoCompiler::compile($poPath, $moPath);

    clearstatcache(true, $moPath);
    $size = is_file($moPath) ? (int) filesize($moPath) : 0;

    if ($size <= 0) {
        throw new RuntimeException('MO file generated but empty (0 bytes).');
    }

    echo "[OK] {$size} bytes\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
