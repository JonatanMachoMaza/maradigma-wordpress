<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Plugin-level debugger utility.
 *
 * Writes structured log lines into:
 * - wp-content/uploads/maradigma/logs/
 *
 * Features:
 * - Channel-based log files
 * - JSON line format
 * - Automatic directory creation
 * - Basic directory protection
 * - File rotation when size exceeds threshold
 * - Read full or tail content
 * - Clear log files
 */
final class Debugger
{
    private const DIR_NAME = 'maradigma/logs';
    private const DEFAULT_CHANNEL = 'debug';
    private const MAX_BYTES = 5_242_880; // 5 MB

    /**
     * Write a log line into the given channel.
     *
     * Example:
     * - Debugger::log('boat-images-sync', 'Tick started', ['offset' => 10]);
     *
     * @param string $channel Log channel / file name without extension.
     * @param string $message Main log message.
     * @param array<string,mixed> $context Extra structured data.
     * @return void
     */
    public static function log(string $channel, string $message, array $context = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        self::write($channel, $message, $context, 'debug');
    }

    /**
     * Write an operational error even when verbose plugin debugging is disabled.
     *
     * Production installations keep routine diagnostics quiet, but failures that
     * stop a background process must remain available from the admin log viewer.
     *
     * @param string $channel Log channel / file name without extension.
     * @param string $message Main log message.
     * @param array<string,mixed> $context Extra structured data.
     * @return void
     */
    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write($channel, $message, $context, 'error');
    }

    /**
     * Persist one structured log entry.
     *
     * @param string $channel Log channel / file name without extension.
     * @param string $message Main log message.
     * @param array<string,mixed> $context Extra structured data.
     * @param string $level Log severity.
     * @return void
     */
    private static function write(string $channel, string $message, array $context, string $level): void
    {

        $channel = self::sanitizeChannel($channel);
        $path = self::getLogFilePath($channel);

        if ($path === '') {
            return;
        }

        self::ensureDirectory();

        self::rotateIfNeeded($path, $channel);

        $line = [
            'datetime' => \gmdate('Y-m-d H:i:s'),
            'channel'  => $channel,
            'level'    => $level,
            'message'  => \trim($message),
            'context'  => self::sanitizeContext($context),
        ];

        $json = \wp_json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!\is_string($json) || $json === '') {
            return;
        }

        @\file_put_contents($path, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Determines whether Maradigma debug logging is enabled.
     */
    private static function isEnabled(): bool
    {
        return (\defined('MARADIGMA_PLUGIN_DEBUG') && MARADIGMA_PLUGIN_DEBUG === true);
    }

    /**
     * Read the full log file contents.
     *
     * @param string $channel Log channel.
     * @return string
     */
    public static function read(string $channel = self::DEFAULT_CHANNEL): string
    {
        $path = self::getLogFilePath($channel);

        if ($path === '' || !\is_file($path) || !\is_readable($path)) {
            return '';
        }

        $content = @\file_get_contents($path);

        return \is_string($content) ? $content : '';
    }

    /**
     * Read the last N lines of a log file.
     *
     * Useful for admin preview widgets.
     *
     * @param string $channel Log channel.
     * @param int $maxLines Maximum lines to return.
     * @return string
     */
    public static function readTail(string $channel = self::DEFAULT_CHANNEL, int $maxLines = 300): string
    {
        $path = self::getLogFilePath($channel);

        if ($path === '' || !\is_file($path) || !\is_readable($path)) {
            return '';
        }

        $maxLines = \max(1, \min($maxLines, 5000));

        $lines = @\file($path, FILE_IGNORE_NEW_LINES);
        if (!\is_array($lines) || $lines === []) {
            return '';
        }

        $lines = \array_slice($lines, -$maxLines);

        return \implode(PHP_EOL, $lines);
    }

    /**
     * Read the last part of a log file by bytes.
     *
     * Useful when the file is large and you do not want to load everything.
     *
     * @param string $channel Log channel.
     * @param int $maxBytes Maximum bytes to read from the end of the file.
     * @return string
     */
    public static function readTailBytes(string $channel = self::DEFAULT_CHANNEL, int $maxBytes = 50000): string
    {
        $path = self::getLogFilePath($channel);

        if ($path === '' || !\is_file($path) || !\is_readable($path)) {
            return '';
        }

        $maxBytes = \max(1024, $maxBytes);

        $size = @\filesize($path);
        if (!\is_int($size) || $size <= 0) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Tail reads need stream seeking for large log files stored in uploads.
        $fp = @\fopen($path, 'rb');
        if ($fp === false) {
            return '';
        }

        $start = \max(0, $size - $maxBytes);
        @\fseek($fp, $start);

        $content = \stream_get_contents($fp);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired close for the seekable log stream above.
        @\fclose($fp);

        if (!\is_string($content) || $content === '') {
            return '';
        }

        return $content;
    }

    /**
     * Clear a log file contents.
     *
     * @param string $channel Log channel.
     * @return bool
     */
    public static function clear(string $channel = self::DEFAULT_CHANNEL): bool
    {
        $channel = self::sanitizeChannel($channel);
        $path = self::getLogFilePath($channel);

        if ($path === '') {
            return false;
        }

        self::ensureDirectory();

        return @\file_put_contents($path, '') !== false;
    }

    /**
     * Check whether a log file exists and is readable.
     *
     * @param string $channel Log channel.
     * @return bool
     */
    public static function exists(string $channel = self::DEFAULT_CHANNEL): bool
    {
        $path = self::getLogFilePath($channel);

        return $path !== '' && \is_file($path) && \is_readable($path);
    }

    /**
     * Get log file size in bytes.
     *
     * @param string $channel Log channel.
     * @return int
     */
    public static function getSize(string $channel = self::DEFAULT_CHANNEL): int
    {
        $path = self::getLogFilePath($channel);

        if ($path === '' || !\is_file($path) || !\is_readable($path)) {
            return 0;
        }

        $size = @\filesize($path);

        return \is_int($size) ? $size : 0;
    }

    /**
     * Get absolute log file path for a channel.
     *
     * Example:
     * - wp-content/uploads/maradigma/logs/boat-images-sync.log
     *
     * @param string $channel Log channel.
     * @return string
     */
    public static function getLogFilePath(string $channel = self::DEFAULT_CHANNEL): string
    {
        $uploadDir = \wp_upload_dir();

        if (!\is_array($uploadDir) || !empty($uploadDir['error']) || empty($uploadDir['basedir'])) {
            return '';
        }

        $channel = self::sanitizeChannel($channel);

        return \trailingslashit((string) $uploadDir['basedir'])
            . self::DIR_NAME
            . '/'
            . $channel
            . '.log';
    }

    /**
     * Get logs directory absolute path.
     *
     * @return string
     */
    public static function getLogsDirPath(): string
    {
        $uploadDir = \wp_upload_dir();

        if (!\is_array($uploadDir) || !empty($uploadDir['error']) || empty($uploadDir['basedir'])) {
            return '';
        }

        return \trailingslashit((string) $uploadDir['basedir']) . self::DIR_NAME . '/';
    }

    /**
     * List available log files in the logs directory.
     *
     * @return array<int,array{name:string,path:string,size:int,modified:int}>
     */
    public static function listFiles(): array
    {
        $dir = self::getLogsDirPath();
        if ($dir === '' || !\is_dir($dir) || !\is_readable($dir)) {
            return [];
        }

        $files = @\scandir($dir);
        if (!\is_array($files) || $files === []) {
            return [];
        }

        $out = [];

        foreach ($files as $file) {
            if (!\is_string($file) || $file === '' || $file === '.' || $file === '..') {
                continue;
            }

            if (!\str_ends_with($file, '.log')) {
                continue;
            }

            $path = $dir . $file;
            if (!\is_file($path)) {
                continue;
            }

            $size = @\filesize($path);
            $mtime = @\filemtime($path);

            $out[] = [
                'name'     => $file,
                'path'     => $path,
                'size'     => \is_int($size) ? $size : 0,
                'modified' => \is_int($mtime) ? $mtime : 0,
            ];
        }

        \usort($out, static function (array $a, array $b): int {
            return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
        });

        return $out;
    }

    /**
     * Ensure logs directory exists and is minimally protected.
     *
     * @return void
     */
    private static function ensureDirectory(): void
    {
        $dir = self::getLogsDirPath();
        if ($dir === '') {
            return;
        }

        if (!\is_dir($dir)) {
            \wp_mkdir_p($dir);
        }

        // Older releases created an executable PHP index file in this
        // plugin-owned directory. Remove it and use inert HTML instead.
        $legacyIndexFile = $dir . 'index.php';
        if (\is_file($legacyIndexFile)) {
            \wp_delete_file($legacyIndexFile);
        }

        $indexFile = $dir . 'index.html';
        if (!\file_exists($indexFile)) {
            @\file_put_contents($indexFile, '');
        }

        $htaccess = $dir . '.htaccess';
        if (!\file_exists($htaccess)) {
            @\file_put_contents($htaccess, "Deny from all\n");
        }

        $webConfig = $dir . 'web.config';
        if (!\file_exists($webConfig)) {
            @\file_put_contents(
                $webConfig,
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n"
            );
        }
    }

    /**
     * Rotate file if too large.
     *
     * Example:
     * - boat-images-sync.log
     * - boat-images-sync-20260319-103012.log
     *
     * @param string $path Absolute current log path.
     * @param string $channel Log channel.
     * @return void
     */
    private static function rotateIfNeeded(string $path, string $channel): void
    {
        if (!\is_file($path)) {
            return;
        }

        $size = @\filesize($path);
        if (!\is_int($size) || $size < self::MAX_BYTES) {
            return;
        }

        $rotated = \dirname($path) . '/'
            . self::sanitizeChannel($channel)
            . '-'
            . \gmdate('Ymd-His')
            . '.log';

        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Log rotation moves a plugin-owned file within the same uploads subdirectory.
        @\rename($path, $rotated);
    }

    /**
     * Sanitize a log channel into a safe file name fragment.
     *
     * @param string $channel
     * @return string
     */
    private static function sanitizeChannel(string $channel): string
    {
        $channel = \strtolower(\trim($channel));
        $channel = \preg_replace('/[^a-z0-9\-_]+/', '-', $channel) ?: '';
        $channel = \trim($channel, '-_');

        return $channel !== '' ? $channel : self::DEFAULT_CHANNEL;
    }

    /**
     * Normalize context into JSON-safe scalar/array values.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value === null) {
                $normalized[(string) $key] = $value;
                continue;
            }

            if (\is_array($value)) {
                $normalized[(string) $key] = self::normalizeArray($value);
                continue;
            }

            if ($value instanceof \Throwable) {
                $normalized[(string) $key] = [
                    'type'    => $value::class,
                    'message' => $value->getMessage(),
                    'file'    => $value->getFile(),
                    'line'    => $value->getLine(),
                ];
                continue;
            }

            if (\is_object($value) && \method_exists($value, '__toString')) {
                $normalized[(string) $key] = (string) $value;
                continue;
            }

            $normalized[(string) $key] = \get_debug_type($value);
        }

        return $normalized;
    }

    /**
     * Recursively normalize arrays into JSON-safe values.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function normalizeArray(array $value): array
    {
        $out = [];

        foreach ($value as $k => $v) {
            if (\is_scalar($v) || $v === null) {
                $out[$k] = $v;
                continue;
            }

            if (\is_array($v)) {
                $out[$k] = self::normalizeArray($v);
                continue;
            }

            if ($v instanceof \Throwable) {
                $out[$k] = [
                    'type'    => $v::class,
                    'message' => $v->getMessage(),
                    'file'    => $v->getFile(),
                    'line'    => $v->getLine(),
                ];
                continue;
            }

            if (\is_object($v) && \method_exists($v, '__toString')) {
                $out[$k] = (string) $v;
                continue;
            }

            $out[$k] = \get_debug_type($v);
        }

        return $out;
    }
}
