<?php
declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Lightweight logger for plugin diagnostics.
 *
 * - Uses error_log
 * - Respects MARADIGMA_PLUGIN_DEBUG
 * - Adds a prefix for easy filtering
 */
final class Logger
{
    private const PREFIX = '[Maradigma]';

    /**
     * Logs a debug message (only when MARADIGMA_PLUGIN_DEBUG is enabled).
     *
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    public static function debug(string $message, array $context = []): void
    {
        self::write('DEBUG', $message, $context);
    }

    /**
     * Logs an info message.
     *
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Logs a warning message.
     *
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        self::write('WARN', $message, $context);
    }

    /**
     * Logs an error message.
     *
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * Logs an exception with trace.
     *
     * @param \Throwable $e
     * @param array<string,mixed> $context
     * @return void
     */
    public static function exception(\Throwable $e, array $context = []): void
    {
        $context['exception'] = [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        // Trace can be large; keep it short-ish
        $context['trace'] = \substr($e->getTraceAsString(), 0, 5000);

        self::write('EXCEPTION', $e->getMessage(), $context);
    }

    /**
     * @param string $level
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    private static function write(string $level, string $message, array $context = []): void
    {
        if (!self::isPluginDebugEnabled()) {
            return;
        }

        $line = self::PREFIX . ' ' . $level . ': ' . $message;

        if (!empty($context)) {
            $json = \json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                $line .= ' | ' . $json;
            }
        }

        \maradigma_debug_log($line);
    }

    /**
     * @return bool
     */
    private static function isPluginDebugEnabled(): bool
    {
        return (\defined('MARADIGMA_PLUGIN_DEBUG') && MARADIGMA_PLUGIN_DEBUG === true);
    }
}
