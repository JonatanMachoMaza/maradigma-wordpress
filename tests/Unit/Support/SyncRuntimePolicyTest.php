<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\SyncRuntimePolicy;
use PHPUnit\Framework\TestCase;

final class SyncRuntimePolicyTest extends TestCase
{
    public function testFreshHeartbeatKeepsLockActive(): void
    {
        self::assertFalse(SyncRuntimePolicy::isLockStale([
            'created_at' => 100,
            'heartbeat_at' => 190,
        ], 200));
    }

    public function testLockExpiresOnlyAfterFullStaleWindow(): void
    {
        self::assertFalse(SyncRuntimePolicy::isLockStale(['created_at' => 100], 219));
        self::assertTrue(SyncRuntimePolicy::isLockStale(['created_at' => 100], 220));
    }

    public function testBatchYieldsBeforePhpExecutionLimit(): void
    {
        self::assertFalse(SyncRuntimePolicy::shouldYield(100.0, 139.99));
        self::assertTrue(SyncRuntimePolicy::shouldYield(100.0, 140.0));
    }

    public function testElapsedMillisecondsIsStable(): void
    {
        self::assertSame(1250, SyncRuntimePolicy::elapsedMilliseconds(10.0, 11.25));
    }
}
