<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Pure timing rules shared by background synchronization workers.
 */
final class SyncRuntimePolicy
{
    public const LOCK_STALE_SECONDS = 120;
    public const BATCH_TIME_BUDGET_SECONDS = 40;

    /**
     * @param array{created_at?:int,heartbeat_at?:int} $lock
     */
    public static function isLockStale(array $lock, int $now): bool
    {
        $createdAt = max(0, (int)($lock['created_at'] ?? 0));
        $heartbeatAt = max(0, (int)($lock['heartbeat_at'] ?? 0));
        $lastActivityAt = max($createdAt, $heartbeatAt);

        return $lastActivityAt <= 0
            || ($now - $lastActivityAt) >= self::LOCK_STALE_SECONDS;
    }

    /**
     * Determines whether yield.
     */
    public static function shouldYield(float $startedAt, float $now): bool
    {
        if ($startedAt <= 0.0 || $now < $startedAt) {
            return false;
        }

        return ($now - $startedAt) >= self::BATCH_TIME_BUDGET_SECONDS;
    }

    /**
     * Calculates the elapsed runtime in milliseconds.
     */
    public static function elapsedMilliseconds(float $startedAt, float $now): int
    {
        if ($startedAt <= 0.0 || $now <= $startedAt) {
            return 0;
        }

        return (int)round(($now - $startedAt) * 1000);
    }
}
