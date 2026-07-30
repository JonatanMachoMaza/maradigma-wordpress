<?php

declare(strict_types=1);

// Constants normally provided by WordPress and the plugin bootstrap. Defining
// them here lets PHPStan analyse classes directly without executing WordPress.
defined('ABSPATH') || define('ABSPATH', dirname(__DIR__, 4) . DIRECTORY_SEPARATOR);
defined('MARADIGMA_PLUGIN_VERSION') || define('MARADIGMA_PLUGIN_VERSION', 'static-analysis');
defined('MARADIGMA_PLUGIN_FILE') || define('MARADIGMA_PLUGIN_FILE', dirname(__DIR__) . '/maradigma.php');
defined('MARADIGMA_PLUGIN_DIR') || define('MARADIGMA_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
defined('MARADIGMA_PLUGIN_URL') || define('MARADIGMA_PLUGIN_URL', 'https://example.test/wp-content/plugins/maradigma/');
defined('MARADIGMA_INCLUDES_DIR') || define('MARADIGMA_INCLUDES_DIR', dirname(__DIR__) . '/includes/');
defined('MARADIGMA_PLUGIN_DEBUG') || define('MARADIGMA_PLUGIN_DEBUG', false);
defined('MARADIGMA_CACHE_ENABLED') || define('MARADIGMA_CACHE_ENABLED', true);
defined('MARADIGMA_PLUGIN_RELEASE_SLUG') || define('MARADIGMA_PLUGIN_RELEASE_SLUG', 'maradigma');
