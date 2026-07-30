<?php
declare(strict_types=1);

namespace Maradigma;

/**
 * Resolves templates with theme override support.
 *
 * Lookup order:
 * 1) Theme override: /wp-content/themes/{theme}/maradigma/{template}.php
 * 2) Plugin templates: /wp-plugin-maradigma/templates/{template}.php
 */
final class TemplateResolver
{
    /**
     * @var string Absolute path to plugin templates directory.
     */
    private string $pluginTemplatesDir;

    /**
     * @var string Relative directory for theme overrides.
     */
    private string $themeOverrideDir;

    /**
     * @param string $pluginTemplatesDir Absolute path to plugin templates directory.
     * @param string $themeOverrideDir Relative dir inside theme for overrides.
     */
    public function __construct(string $pluginTemplatesDir, string $themeOverrideDir = 'maradigma')
    {
        $this->pluginTemplatesDir = \rtrim($pluginTemplatesDir, '/\\');
        $this->themeOverrideDir = \trim($themeOverrideDir, '/\\');
    }

    /**
     * Resolves a template file by name.
     *
     * @param string $templateName E.g. 'boats-archive' (without .php)
     * @return string|null Absolute path or null if not found.
     */
    public function resolve(string $templateName): ?string
    {
        $templateName = \trim($templateName);
        if ($templateName === '') {
            return null;
        }

        $file = $templateName . '.php';

        // 1) Theme override
        $themePath = $this->resolveThemeOverride($file);
        if ($themePath !== null) {
            return $themePath;
        }

        // 2) Plugin templates
        $pluginPath = $this->pluginTemplatesDir . DIRECTORY_SEPARATOR . $file;
        if (\is_file($pluginPath)) {
            return $pluginPath;
        }

        return null;
    }

    /**
     * @param string $file
     * @return string|null
     */
    private function resolveThemeOverride(string $file): ?string
    {
        if (!\function_exists('get_stylesheet_directory')) {
            return null;
        }

        $base = (string) \get_stylesheet_directory();
        if ($base === '') {
            return null;
        }

        $candidate = $base . DIRECTORY_SEPARATOR . $this->themeOverrideDir . DIRECTORY_SEPARATOR . $file;
        return \is_file($candidate) ? $candidate : null;
    }
}
