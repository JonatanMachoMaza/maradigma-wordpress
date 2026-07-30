<?php
declare(strict_types=1);

namespace Maradigma;

/**
 * Internal environment configuration.
 *
 * This is BCH-internal only.
 * Clients must NOT configure or touch this.
 *
 * Environment values:
 *  - local
 *  - staging
 *  - production
 */
final class Environment
{
    public const ENV_LOCAL      = 'local';
    public const ENV_STAGING    = 'staging';
    public const ENV_PRODUCTION = 'production';

    /**
     * Returns current plugin environment.
     */
    public static function getEnv(): string
    {
        if (\defined('MARADIGMA_ENV')) {
            return \strtolower((string) MARADIGMA_ENV);
        }

        // Safety fallback (should never happen)
        return self::ENV_PRODUCTION;
    }

    /**
     * Returns API base URL based on current environment.
     */
    public static function getApiBaseUrl(): string
    {
        switch (self::getEnv()) {
            case self::ENV_LOCAL:
                return 'http://localhost:8080/api/external/v1';

            case self::ENV_STAGING:
                return 'https://staging.maradigma.com/api/external/v1';

            case self::ENV_PRODUCTION:
            default:
                return 'https://app.maradigma.com/api/external/v1';
        }
    }

    /**
     * Human label (optional, for admin UI).
     */
    public static function getEnvLabel(): string
    {
        switch (self::getEnv()) {
            case self::ENV_LOCAL:
                return 'Local';

            case self::ENV_STAGING:
                return 'Staging';

            case self::ENV_PRODUCTION:
            default:
                return 'Production';
        }
    }
}
