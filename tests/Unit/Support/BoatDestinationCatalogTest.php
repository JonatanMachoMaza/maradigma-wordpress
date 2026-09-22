<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatDestinationCatalog;
use PHPUnit\Framework\TestCase;

final class BoatDestinationCatalogTest extends TestCase
{
    public function testItCountsBoatsPerDestinationAndOrdersIslandsFirst(): void
    {
        $catalog = BoatDestinationCatalog::fromBoats([
            self::boat(2410, [self::dest(1, 'Ibiza', 'locality'), self::dest(1704, 'Ibiza', 'island'), self::dest(3, 'Spain', 'country')]),
            self::boat(2978, [self::dest(13, 'Eivissa', 'locality'), self::dest(1, 'Ibiza', 'locality'), self::dest(1704, 'Ibiza', 'island')]),
            self::boat(3113, [self::dest(407, 'Badalona', 'locality'), self::dest(92, 'Catalonia', 'administrative_area')]),
        ]);

        self::assertSame(
            ['destination:1704', 'destination:407', 'destination:13', 'destination:1', 'destination:92', 'destination:3'],
            \array_column($catalog, 'value')
        );
        self::assertSame([2, 1, 1, 2, 1, 1], \array_column($catalog, 'boats'));
    }

    public function testDuplicatedRowsInOneBoatAreCountedOnce(): void
    {
        $catalog = BoatDestinationCatalog::fromBoats([
            self::boat(1, [self::dest(1704, 'Ibiza', 'island'), self::dest(1704, 'Ibiza', 'island')]),
        ]);

        self::assertCount(1, $catalog);
        self::assertSame(1, $catalog[0]['boats']);
    }

    public function testLegacySingularDestinationIsUsedWhenTheListIsMissing(): void
    {
        $catalog = BoatDestinationCatalog::fromBoats([
            ['id' => 5, 'destination' => self::dest(58, 'Marbella', 'locality')],
        ]);

        self::assertSame('destination:58', $catalog[0]['value'] ?? null);
    }

    public function testInvalidRowsAreIgnored(): void
    {
        $catalog = BoatDestinationCatalog::fromBoats([
            'not a boat',
            ['destinations' => [
                ['id' => 0, 'text' => 'Zero'],
                ['id' => 9, 'text' => ''],
                ['id' => 10, 'text' => 'Port', 'entity_type' => 'port'],
                'broken',
            ]],
        ]);

        self::assertSame([], $catalog);
    }

    public function testSearchMatchesNameOrSubtitleIgnoringCase(): void
    {
        $catalog = BoatDestinationCatalog::fromBoats([
            self::boat(1, [
                self::dest(1704, 'Ibiza', 'island', 'Ibiza, Illes Balears, España'),
                self::dest(2, 'Islas Baleares', 'administrative_area', 'Islas Baleares, ES'),
                self::dest(407, 'Badalona', 'locality', 'Badalona, ES'),
            ]),
        ]);

        self::assertSame(['destination:1704', 'destination:2'], \array_column(BoatDestinationCatalog::search($catalog, 'BALEAR'), 'value'));
        self::assertCount(3, BoatDestinationCatalog::search($catalog, '  '));
    }

    public function testPickAcceptsTokensAndBareIds(): void
    {
        $catalog = BoatDestinationCatalog::fromBoats([
            self::boat(1, [self::dest(1704, 'Ibiza', 'island'), self::dest(407, 'Badalona', 'locality')]),
        ]);

        self::assertSame(['destination:1704', 'destination:407'], \array_column(BoatDestinationCatalog::pick($catalog, ['407', 'destination:1704', 'port:2', 'junk']), 'value'));
    }

    /**
     * @param list<array<string,mixed>> $destinations
     * @return array<string,mixed>
     */
    private static function boat(int $id, array $destinations): array
    {
        return ['id' => $id, 'destinations' => $destinations];
    }

    /**
     * @return array<string,mixed>
     */
    private static function dest(int $id, string $text, string $type, string $subtitle = ''): array
    {
        return [
            'value'       => 'destination:' . $id,
            'text'        => $text,
            'entity_type' => 'destination',
            'id'          => $id,
            'place_type'  => $type,
            'subtitle'    => $subtitle,
        ];
    }
}
