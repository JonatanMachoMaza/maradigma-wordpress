<?php
/**
 * Plugin Name: Maradigma
 * Plugin URI: https://maradigma.com/es/wordpress-plugin/
 * Description: Connect WordPress with Maradigma to sync boats, build fleet pages, display availability and prices, and accept online bookings.
 * Version: 0.1.193
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Maradigma
 * Author URI: https://maradigma.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: maradigma
 * Domain Path: /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin constants.
 */
define('MARADIGMA_PLUGIN_VERSION', '0.1.193');
define('MARADIGMA_PLUGIN_FILE', __FILE__);
define('MARADIGMA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MARADIGMA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MARADIGMA_INCLUDES_DIR', MARADIGMA_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR);

// --------------------------------------------------
// Maradigma internal environment
// Values: local | staging | production
// --------------------------------------------------
define('MARADIGMA_ENV', 'production');

define('MARADIGMA_ASSETS_MIN_CSS', true);
define('MARADIGMA_ASSETS_MIN_JS', true);

if (!defined('MARADIGMA_PLUGIN_DEBUG')) {
    define('MARADIGMA_PLUGIN_DEBUG', false);
}

if (!function_exists('maradigma_debug_log')) {
    /**
     * Writes plugin diagnostics only when MARADIGMA_PLUGIN_DEBUG is explicitly enabled.
     *
     * @param mixed $message
     */
    function maradigma_debug_log($message): void
    {
        if (!defined('MARADIGMA_PLUGIN_DEBUG') || MARADIGMA_PLUGIN_DEBUG !== true) {
            return;
        }

        if (!is_scalar($message)) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($message) : json_encode($message);
            $message = is_string($encoded) ? $encoded : '';
        }

        if ($message !== '') {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Explicit opt-in diagnostics, disabled by default in production.
            \error_log((string) $message);
        }
    }
}

// --------------------------------------------------
// Cache toggle (transients)
// --------------------------------------------------
// true  => use Cache layer (transients)
// false => always hit ExternalApiClient (no transients)
define('MARADIGMA_CACHE_ENABLED', true);

/**
 * Stable release/package slug used for generated distributable builds.
 *
 * This value defines the canonical root folder name inside the generated ZIP
 * package and the filename prefix of the release archive, independently from
 * the local plugin directory name on each development machine.
 *
 * Examples:
 * - ZIP filename: maradigma-0.1.44.zip
 * - Root folder inside ZIP: /maradigma
 *
 * This helps keep release artifacts consistent across environments such as:
 * - local development
 * - CI/CD pipelines
 * - manual packaging workflows
 *
 * Important:
 * - This is intended for packaging/distribution purposes only.
 * - It should remain stable over time unless you intentionally want to change
 *   the public distributable package name.
 */
define('MARADIGMA_PLUGIN_RELEASE_SLUG', 'maradigma');

/**
 * Autoload (PSR-4 like) for Maradigma\* classes.
 */
require_once MARADIGMA_INCLUDES_DIR . 'Autoload.php';
// Register autoload for both /includes and /integrations.
\Maradigma\Autoload::register(MARADIGMA_PLUGIN_DIR);

/**
 * Bootstrap plugin.
 */
if (class_exists(\Maradigma\Plugin::class)) {
    \Maradigma\Plugin::init();
} else {
    if (defined('MARADIGMA_PLUGIN_DEBUG') && MARADIGMA_PLUGIN_DEBUG === true) {
        maradigma_debug_log('[Maradigma] ERROR: Plugin class not found. Autoload may be misconfigured.');
    }
}
