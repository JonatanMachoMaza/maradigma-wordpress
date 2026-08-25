<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit;

use Maradigma\ShortcodeRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ShortcodeRegistryTest extends TestCase
{
    public function testBoatSearchDefaultsKeepLengthOrderingAvailableToShortcodeNormalization(): void
    {
        $defaults = ShortcodeRegistry::getBoatsSearchAttributeDefaults();

        self::assertSame('boats', $defaults['id_group'] ?? null);
        self::assertSame('0', $defaults['order_by'] ?? null);
        self::assertArrayHasKey('boat_length', $defaults);
    }

    public function testUndatedArchiveUsesMandatoryAdditionalsTotalWhenCardDisplaysIt(): void
    {
        $filters = $this->applyUndatedPriceOrderCriteria(
            ['id_group' => 'boats', 'order_by' => 1],
            '<span>{{price_from_service_with_mandatory_additionals_total}}</span>'
        );

        self::assertSame(
            'total_with_mandatory_additionals',
            $filters['undated_price_order_criteria'] ?? null
        );
    }

    public function testUndatedArchiveKeepsBasePriceBehaviorWhenCardDoesNotDisplayMandatoryTotal(): void
    {
        $filters = $this->applyUndatedPriceOrderCriteria(
            ['id_group' => 'boats', 'order_by' => 2],
            '<span>{{price_from_total}}</span>'
        );

        self::assertArrayNotHasKey('undated_price_order_criteria', $filters);
    }

    public function testDatedArchiveNeverSendsUndatedPriceCriterion(): void
    {
        $filters = $this->applyUndatedPriceOrderCriteria(
            [
                'date_start' => '2026-08-24',
                'date_end' => '2026-08-31',
                'undated_price_order_criteria' => 'total_with_mandatory_additionals',
            ],
            '<span>{{price_from_service_with_mandatory_additionals_total}}</span>'
        );

        self::assertArrayNotHasKey('undated_price_order_criteria', $filters);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function applyUndatedPriceOrderCriteria(array $filters, string $template): array
    {
        $method = new ReflectionMethod(ShortcodeRegistry::class, 'applyUndatedPriceOrderCriteria');
        $result = $method->invoke(null, $filters, $template);

        self::assertIsArray($result);

        return $result;
    }
}
