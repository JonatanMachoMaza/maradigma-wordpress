<?php
declare(strict_types=1);

namespace Maradigma;

/**
 * Minimal PSR-4-like autoloader for the plugin.
 *
 * Namespace root: Maradigma\
 * Base dir: plugin root (we map most classes to /includes and Integrations* to /integrations)
 *
 * It supports subfolders like:
 * - Maradigma\Support\Logger  => includes/Support/Logger.php
 */
final class Autoload
{
    /**
     * Registers the autoloader.
     *
     * @param string $pluginDir Absolute path to plugin root directory.
     * @return void
     */
    public static function register(string $pluginDir): void
    {
        $pluginDir = rtrim($pluginDir, '/\\') . DIRECTORY_SEPARATOR;
        $includesDir = $pluginDir . 'includes' . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($pluginDir, $includesDir): void {
            $prefix = 'Maradigma\\';

            if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                return;
            }

            $relative = substr($class, strlen($prefix)); // e.g. Support\Logger
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            // Default location: /includes
            $file = $includesDir . $relativePath;

            // Integrations live in /integrations (outside /includes)
            if (!is_file($file) && strncmp($relative, 'Integrations\\', strlen('Integrations\\')) === 0) {
                $suffix = substr($relativePath, strlen('Integrations' . DIRECTORY_SEPARATOR));
                $file = $pluginDir . 'integrations' . DIRECTORY_SEPARATOR . $suffix;
            }

            if (is_file($file)) {
                require_once $file;
            }
        });
    }
}
