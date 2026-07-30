<?php
declare(strict_types=1);

namespace Maradigma;
use Maradigma\Support\RuntimeContext;

/**
 * Centralizes cache keys and TTL policies for plugin data.
 *
 * This avoids "random" transient keys across the codebase and makes it easy to:
 * - Invalidate caches
 * - Change TTLs for different endpoints
 * - Namespace by site / language / tenant
 */
final class CachePolicy
{
    public const GROUP = 'maradigma';

    // Default TTLs in seconds
    public const TTL_SHORT  = 60;      // 1 min
    public const TTL_MEDIUM = 300;     // 5 min
    public const TTL_LONG   = 3600;    // 1 hour

    /**
     * Returns a standard cache key for an endpoint + params.
     *
     * @param string $name   Logical name: 'boats_archive', 'boat_single', 'search'
     * @param array<string,mixed> $params
     * @param string|null $lang
     * @return string
     */
    public static function key(string $name, array $params = [], ?string $lang = null): string
    {
        $lang = $lang ?: RuntimeContext::getLanguage();

        // Ensure stable serialization
        \ksort($params);

        $payload = [
            'name' => $name,
            'lang' => $lang,
            'site' => self::siteId(),
            'params' => $params,
        ];

        $json = \json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $hash = $json !== false ? \hash('sha256', $json) : \hash('sha256', $name);

        return self::GROUP . ':' . $name . ':' . $hash;
    }

    /**
     * Returns the recommended TTL by logical cache name.
     *
     * @param string $name
     * @return int
     */
    public static function ttl(string $name): int
    {
        return match ($name) {
            'boat_single'   => self::TTL_MEDIUM,
            'boats_archive' => self::TTL_MEDIUM,
            'search'        => self::TTL_SHORT,
            default         => self::TTL_MEDIUM,
        };
    }

    /**
     * @return int
     */
    private static function siteId(): int
    {
        if (\function_exists('get_current_blog_id')) {
            return (int) \get_current_blog_id();
        }
        return 1;
    }
}
