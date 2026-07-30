<?php
declare(strict_types=1);

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

// ✅ Hard block: only CLI
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

// ---------------------------------------------------------------------
// Bootstrap minimal del plugin (para que funcione tu autoload interno)
// ---------------------------------------------------------------------
if (!defined('MARADIGMA_PLUGIN_DIR')) {
    define('MARADIGMA_PLUGIN_DIR', $root . DIRECTORY_SEPARATOR);
}
if (!defined('MARADIGMA_INCLUDES_DIR')) {
    define('MARADIGMA_INCLUDES_DIR', MARADIGMA_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR);
}

// 1) Composer autoload (si existe)
$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

// 2) Autoload interno del plugin (tu caso)
$pluginAutoload = MARADIGMA_INCLUDES_DIR . 'Autoload.php';
if (!is_file($pluginAutoload)) {
    fwrite(STDERR, "ERROR: Plugin autoload not found at: {$pluginAutoload}\n");
    exit(1);
}
require_once $pluginAutoload;

if (!class_exists(\Maradigma\Autoload::class)) {
    fwrite(STDERR, "ERROR: Maradigma\\Autoload not found after requiring Autoload.php\n");
    exit(1);
}
\Maradigma\Autoload::register(MARADIGMA_PLUGIN_DIR);

// Ahora ya debería existir la clase
if (!class_exists(\Maradigma\Support\PoToMoCompiler::class)) {
    fwrite(STDERR, "ERROR: Class Maradigma\\Support\\PoToMoCompiler not found. Check file path + namespace.\n");
    exit(1);
}

use Maradigma\Support\PoToMoCompiler;

// ---------------------------------------------------------------------
// Helpers CLI
// ---------------------------------------------------------------------
$argv = $_SERVER['argv'] ?? [];
$argc = $_SERVER['argc'] ?? 0;

function usage(): void
{
    echo <<<TXT
Maradigma PO->MO compiler (CLI)

Usage:
  php bin/compile-translations.php --all
  php bin/compile-translations.php --po=languages/maradigma-es_ES.po
  php bin/compile-translations.php --po=... --mo=...

Options:
  --all                 Compile all *.po inside /languages
  --po=PATH             Path to a .po file (relative to plugin root or absolute)
  --mo=PATH             Optional output .mo path (relative to plugin root or absolute)
  --languages-dir=PATH  Optional languages directory (default: languages)
  --help                Show this help

TXT;
}

if ($argc <= 1 || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    usage();
    exit(0);
}

// Parse args
$args = [
    'all'           => in_array('--all', $argv, true),
    'po'            => null,
    'mo'            => null,
    'languages_dir' => 'languages',
];

foreach ($argv as $a) {
    if (str_starts_with($a, '--po=')) {
        $args['po'] = substr($a, 5);
    } elseif (str_starts_with($a, '--mo=')) {
        $args['mo'] = substr($a, 5);
    } elseif (str_starts_with($a, '--languages-dir=')) {
        $args['languages_dir'] = substr($a, 16);
    }
}

$resolvePath = static function (string $p) use ($root): string {
    $p = trim($p);
    if ($p === '') {
        return '';
    }
    // Absolute Windows (C:\) or UNC (\\server\share) or unix (/)
    if (preg_match('~^[A-Za-z]:\\\\~', $p) === 1 || str_starts_with($p, '\\\\') || str_starts_with($p, '/')) {
        return $p;
    }
    return $root . DIRECTORY_SEPARATOR . ltrim($p, '/\\');
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

        echo "Plugin root: {$root}\n";
        echo "Languages dir: {$langDir}\n\n";

        $ok = 0;
        $fail = 0;

        foreach ($files as $poPath) {
            $poPath = (string)$poPath;
            $moPath = preg_replace('~\.po$~i', '.mo', $poPath) ?: ($poPath . '.mo');

            echo "Compiling:\n  PO: {$poPath}\n  MO: {$moPath}\n";

            PoToMoCompiler::compile($poPath, $moPath);

            clearstatcache(true, $moPath);
            $size = is_file($moPath) ? (int)filesize($moPath) : 0;

            if ($size > 0) {
                echo "  ✅ OK ({$size} bytes)\n\n";
                $ok++;
            } else {
                echo "  ❌ FAILED (empty output)\n\n";
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
    $size = is_file($moPath) ? (int)filesize($moPath) : 0;

    if ($size <= 0) {
        throw new RuntimeException('MO file generated but empty (0 bytes).');
    }

    echo "✅ OK ({$size} bytes)\n";
    exit(0);

} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
