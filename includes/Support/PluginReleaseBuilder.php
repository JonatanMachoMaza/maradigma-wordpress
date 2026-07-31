<?php

declare(strict_types=1);

namespace Maradigma\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Builds a clean distributable ZIP package for the plugin.
 *
 * This builder is intended to be executed from CLI through /bin/build-release.php
 * and creates a release archive excluding development-only files and directories
 * such as node_modules, .git, editor metadata, temporary files and similar.
 *
 * Typical output:
 * - /release/maradigma-0.1.44.zip
 *
 * Notes:
 * - The generated ZIP uses a stable release slug, independent from the local folder name.
 * - The builder copies the plugin into a temporary directory first, applying
 *   exclusion rules, and then compresses that cleaned copy.
 * - This class is framework-agnostic and does not require WordPress runtime.
 */
final class PluginReleaseBuilder
{
    /**
     * Absolute path to the plugin root directory.
     */
    private string $pluginRootPath;

    /**
     * Real local plugin root directory basename.
     */
    private string $pluginRootName;

    /**
     * Stable release slug used inside the ZIP and as ZIP filename prefix.
     */
    private string $releaseSlug;

    /**
     * Absolute path to the main plugin file.
     */
    private string $pluginMainFile;

    /**
     * Absolute path to the release output directory.
     */
    private string $releaseDirPath;

    /**
     * Absolute path to the temporary build directory.
     */
    private string $tempBuildDirPath;

    /**
     * Directory names to exclude entirely from the package.
     *
     * @var array<int,string>
     */
    private array $excludedDirectories = [
        '.git',
        '.github',
        '.idea',
        '.vscode',
        '.codebase-memory',
        '.phpstan.cache',
        '.phpunit.cache',
        'node_modules',
        'release',
        'tests',
        'test',
        'tools',
        'vendor',
        'tmp',
        'temp',
        '.cache',
    ];

    /**
     * Runtime asset directories whose `vendor` segment must remain in releases.
     *
     * @var array<int,string>
     */
    private array $runtimeVendorDirectories = [
        'assets/dist/css/vendor',
        'assets/dist/js/vendor',
    ];

    /**
     * File basenames to exclude entirely from the package.
     *
     * @var array<int,string>
     */
    private array $excludedFiles = [
        '.DS_Store',
        'Thumbs.db',
        '.gitignore',
        '.gitattributes',
        'AGENTS.md',
        'RTK.md',
        'composer.json',
        'package.json',
        'package-lock.json',
        'yarn.lock',
        'pnpm-lock.yaml',
        'composer.lock',
        'phpstan-baseline.neon',
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpunit.xml',
        'phpunit.xml.dist',
        'README.md',
        'vite.config.js',
        'vite.config.mjs',
    ];

    /**
     * Relative paths to exclude explicitly.
     *
     * Use forward slashes and paths relative to plugin root.
     *
     * @var array<int,string>
     */
    private array $excludedRelativePaths = [
        'bin',
        'languages',
        'vendors/select2/i18n',
        'includes/Support/PluginReleaseBuilder.php',
        'includes/Support/PoToMoCompiler.php',
    ];

    /**
     * Relative file suffixes to exclude.
     *
     * @var array<int,string>
     */
    private array $excludedSuffixes = [
        '.map',
        '.log',
        '.tmp',
        '.swp',
    ];

    /**
     * @param string $pluginRootPath Absolute path to plugin root.
     * @param string $pluginMainFile Absolute path to the main plugin file.
     * @param string|null $releaseSlug Optional explicit stable release slug.
     */
    public function __construct(string $pluginRootPath, string $pluginMainFile, ?string $releaseSlug = null)
    {
        $pluginRootPath = rtrim($pluginRootPath, DIRECTORY_SEPARATOR);
        $pluginMainFile = rtrim($pluginMainFile, DIRECTORY_SEPARATOR);

        if ($pluginRootPath === '' || !is_dir($pluginRootPath)) {
            throw new RuntimeException('Plugin root path is invalid or does not exist.');
        }

        if ($pluginMainFile === '' || !is_file($pluginMainFile)) {
            throw new RuntimeException('Plugin main file is invalid or does not exist.');
        }

        $this->pluginRootPath = $pluginRootPath;
        $this->pluginRootName = basename($pluginRootPath);
        $this->pluginMainFile = $pluginMainFile;
        $this->releaseSlug = $this->resolveReleaseSlug($releaseSlug);
        $this->releaseDirPath = $this->pluginRootPath . DIRECTORY_SEPARATOR . 'release';
        $this->tempBuildDirPath = $this->pluginRootPath . DIRECTORY_SEPARATOR . '.build-release-temp';
    }

    /**
     * Builds the release ZIP package.
     *
     * @return array{
     *     version:string,
     *     zip_path:string,
     *     zip_filename:string,
     *     release_dir:string,
     *     release_slug:string
     * }
     */
    public function build(): array
    {
        $version = $this->detectPluginVersion();
        $zipFileName = $this->releaseSlug . '-' . $version . '.zip';
        $zipPath = $this->releaseDirPath . DIRECTORY_SEPARATOR . $zipFileName;

        $this->prepareDirectories();
        $this->cleanupTempBuildDirectory();
        $this->copyPluginToTempBuild();
        $this->createZipFromTempBuild($zipPath);
        $this->cleanupTempBuildDirectory();

        return [
            'version'      => $version,
            'zip_path'     => $zipPath,
            'zip_filename' => $zipFileName,
            'release_dir'  => $this->releaseDirPath,
            'release_slug' => $this->releaseSlug,
        ];
    }

    /**
     * Resolves the stable release slug.
     */
    private function resolveReleaseSlug(?string $releaseSlug): string
    {
        $releaseSlug = is_string($releaseSlug) ? trim($releaseSlug) : '';

        if ($releaseSlug !== '') {
            return $this->sanitizeReleaseSlug($releaseSlug);
        }

        $constantSlug = '';

        if (defined('MARADIGMA_PLUGIN_RELEASE_SLUG')) {
            $constantSlug = trim((string) MARADIGMA_PLUGIN_RELEASE_SLUG);
        } else {
            $contents = file_get_contents($this->pluginMainFile);

            if (is_string($contents) && $contents !== '') {
                if (
                    preg_match(
                        "/define\s*\(\s*'MARADIGMA_PLUGIN_RELEASE_SLUG'\s*,\s*'([^']+)'\s*\)/",
                        $contents,
                        $matches
                    ) === 1
                ) {
                    $constantSlug = trim((string) ($matches[1] ?? ''));
                }
            }
        }

        if ($constantSlug !== '') {
            return $this->sanitizeReleaseSlug($constantSlug);
        }

        return $this->sanitizeReleaseSlug($this->pluginRootName);
    }

    /**
     * Sanitizes release slug to a safe ZIP/plugin folder name.
     */
    private function sanitizeReleaseSlug(string $slug): string
    {
        $slug = trim($slug);
        $slug = str_replace('\\', '-', $slug);
        $slug = str_replace('/', '-', $slug);
        $slug = preg_replace('/[^a-zA-Z0-9\-_]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');

        if ($slug === '') {
            throw new RuntimeException('Release slug is invalid after sanitization.');
        }

        return $slug;
    }

    /**
     * Detects plugin version from the main plugin file header.
     */
    private function detectPluginVersion(): string
    {
        $contents = file_get_contents($this->pluginMainFile);

        if ($contents === false || trim($contents) === '') {
            return '0.0.0';
        }

        if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $matches) === 1) {
            $version = trim((string) ($matches[1] ?? ''));
            if ($version !== '') {
                return $version;
            }
        }

        if (preg_match("/define\s*\(\s*'MARADIGMA_PLUGIN_VERSION'\s*,\s*'([^']+)'\s*\)/", $contents, $matches) === 1) {
            $version = trim((string) ($matches[1] ?? ''));
            if ($version !== '') {
                return $version;
            }
        }

        return '0.0.0';
    }

    /**
     * Ensures release and temp directories are ready.
     */
    private function prepareDirectories(): void
    {
        if (!is_dir($this->releaseDirPath) && !mkdir($this->releaseDirPath, 0775, true) && !is_dir($this->releaseDirPath)) {
            throw new RuntimeException('Could not create release directory: ' . $this->releaseDirPath);
        }
    }

    /**
     * Removes previous temporary build directory, if any.
     */
    private function cleanupTempBuildDirectory(): void
    {
        if (!is_dir($this->tempBuildDirPath)) {
            return;
        }

        $this->removeDirectoryRecursive($this->tempBuildDirPath);
    }

    /**
     * Removes a directory and all of its contents recursively.
     */
    private function removeDirectoryRecursive(string $directory): void
    {
        $items = @scandir($directory);
        if ($items === false) {
            throw new RuntimeException('Could not read temp directory: ' . $directory);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectoryRecursive($path);
                continue;
            }

            @chmod($path, 0664);

            if (!@unlink($path) && file_exists($path)) {
                throw new RuntimeException('Could not remove temp file: ' . $path);
            }
        }

        clearstatcache(true, $directory);
        @chmod($directory, 0775);

        if (@rmdir($directory)) {
            return;
        }

        $remaining = @scandir($directory);
        $remainingItems = is_array($remaining)
            ? array_values(array_diff($remaining, ['.', '..']))
            : [];

        throw new RuntimeException(
            'Could not remove temp directory: ' . $directory
            . ($remainingItems !== [] ? ' (remaining: ' . implode(', ', $remainingItems) . ')' : '')
        );
    }

    /**
     * Copies plugin files to temp build directory applying exclusion rules.
     */
    private function copyPluginToTempBuild(): void
    {
        $destinationRoot = $this->tempBuildDirPath . DIRECTORY_SEPARATOR . $this->releaseSlug;

        if (!mkdir($destinationRoot, 0775, true) && !is_dir($destinationRoot)) {
            throw new RuntimeException('Could not create temp destination root: ' . $destinationRoot);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->pluginRootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();

            if (str_starts_with($sourcePath, $this->tempBuildDirPath)) {
                continue;
            }

            $relativePath = $this->normalizeRelativePath(
                substr($sourcePath, strlen($this->pluginRootPath) + 1)
            );

            if ($relativePath === '') {
                continue;
            }

            if ($this->shouldExclude($relativePath, $item->isDir(), $item->getFilename())) {
                continue;
            }

            $targetPath = $destinationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            if ($item->isDir()) {
                if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                    throw new RuntimeException('Could not create directory in temp build: ' . $targetPath);
                }

                continue;
            }

            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Could not create target parent directory: ' . $targetDir);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException('Could not copy file: ' . $sourcePath . ' -> ' . $targetPath);
            }
        }
    }

    /**
     * Creates the final ZIP archive from the temp build directory.
     */
    private function createZipFromTempBuild(string $zipPath): void
    {
        if (is_file($zipPath) && !unlink($zipPath)) {
            throw new RuntimeException('Could not overwrite existing ZIP: ' . $zipPath);
        }

        $zip = new ZipArchive();

        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            throw new RuntimeException('Could not create ZIP archive: ' . $zipPath);
        }

        $sourceRoot = $this->tempBuildDirPath . DIRECTORY_SEPARATOR . $this->releaseSlug;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $fullPath = $item->getPathname();
            $relativeSuffix = substr($fullPath, strlen($sourceRoot) + 1);
            $relativeSuffix = $relativeSuffix === false ? '' : $relativeSuffix;
            $relativeSuffix = $this->normalizeRelativePath($relativeSuffix);
            $relativePath = $this->releaseSlug . ($relativeSuffix !== '' ? '/' . $relativeSuffix : '');

            if ($item->isDir()) {
                $zip->addEmptyDir(rtrim($relativePath, '/'));
                continue;
            }

            if (!$zip->addFile($fullPath, $relativePath)) {
                $zip->close();
                throw new RuntimeException('Could not add file to ZIP: ' . $relativePath);
            }
        }

        if (!$zip->close()) {
            throw new RuntimeException('Could not finalize ZIP archive.');
        }
    }

    /**
     * Determines whether a file or directory must be excluded.
     */
    private function shouldExclude(string $relativePath, bool $isDirectory, string $basename): bool
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $basename = trim($basename);

        if ($relativePath === '') {
            return false;
        }

        foreach ($this->excludedRelativePaths as $excludedRelativePath) {
            $excludedRelativePath = $this->normalizeRelativePath($excludedRelativePath);

            if ($relativePath === $excludedRelativePath || str_starts_with($relativePath, $excludedRelativePath . '/')) {
                return true;
            }
        }

        $segments = explode('/', $relativePath);

        foreach ($segments as $segment) {
            if ($segment === 'vendor' && $this->isRuntimeVendorAssetPath($relativePath)) {
                continue;
            }

            if ($segment !== '' && in_array($segment, $this->excludedDirectories, true)) {
                return true;
            }
        }

        if (!$isDirectory && in_array($basename, $this->excludedFiles, true)) {
            return true;
        }

        if (!$isDirectory) {
            foreach ($this->excludedSuffixes as $suffix) {
                if ($suffix !== '' && str_ends_with($basename, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine whether a path belongs to a distributable third-party asset bundle.
     */
    private function isRuntimeVendorAssetPath(string $relativePath): bool
    {
        foreach ($this->runtimeVendorDirectories as $runtimeDirectory) {
            if ($relativePath === $runtimeDirectory || str_starts_with($relativePath, $runtimeDirectory . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes a relative path to forward slashes.
     */
    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = trim($path);
        $path = trim($path, '/');

        return $path;
    }
}
