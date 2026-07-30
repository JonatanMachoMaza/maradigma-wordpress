<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit;

use Maradigma\CachePolicy;
use PHPUnit\Framework\TestCase;

final class CachePolicyTest extends TestCase
{
    public function testKeyIsStableRegardlessOfParameterOrder(): void
    {
        $first = CachePolicy::key('boats_archive', ['page' => 2, 'builder' => 302], 'es');
        $second = CachePolicy::key('boats_archive', ['builder' => 302, 'page' => 2], 'es');

        self::assertSame($first, $second);
        self::assertStringStartsWith('maradigma:boats_archive:', $first);
    }

    public function testDifferentLanguagesProduceDifferentKeys(): void
    {
        self::assertNotSame(
            CachePolicy::key('boat_single', ['id' => 304], 'es'),
            CachePolicy::key('boat_single', ['id' => 304], 'en')
        );
    }

    public function testTtlPolicyUsesExpectedBuckets(): void
    {
        self::assertSame(CachePolicy::TTL_SHORT, CachePolicy::ttl('search'));
        self::assertSame(CachePolicy::TTL_MEDIUM, CachePolicy::ttl('boat_single'));
        self::assertSame(CachePolicy::TTL_MEDIUM, CachePolicy::ttl('unknown'));
    }
}
