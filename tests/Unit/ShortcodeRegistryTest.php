<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit;

use Maradigma\ShortcodeRegistry;
use PHPUnit\Framework\TestCase;

final class ShortcodeRegistryTest extends TestCase
{
    public function testBoatSearchDefaultsKeepLengthOrderingAvailableToShortcodeNormalization(): void
    {
        $defaults = ShortcodeRegistry::getBoatsSearchAttributeDefaults();

        self::assertSame('boats', $defaults['id_group'] ?? null);
        self::assertSame('0', $defaults['order_by'] ?? null);
        self::assertArrayHasKey('boat_length', $defaults);
    }
}
