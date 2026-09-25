<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatBasePortCatalog;
use PHPUnit\Framework\TestCase;

final class BoatBasePortCatalogTest extends TestCase
{
    /**
     * Rows as the API returns them, plus values the catalogue must ignore.
     *
     * @return list<mixed>
     */
    private static function boats(): array
    {
        return [
            ['id' => 1, 'boat_base_port' => 2, 'boat_base_port_name' => 'Marina Ibiza'],
            ['id' => 2, 'boat_base_port' => '2', 'boat_base_port_name' => 'Marina Ibiza'],
            ['id' => 3, 'boat_base_port' => 7, 'boat_base_port_name' => 'Club Náutico Sant Antoni'],
            ['id' => 4, 'boat_base_port' => 0, 'boat_base_port_name' => 'No port'],
            ['id' => 5, 'boat_base_port' => 9, 'boat_base_port_name' => '   '],
            'not a row',
            ['id' => 6],
        ];
    }

    public function testItBuildsThePortsOfTheBoatsAndCountsThem(): void
    {
        $catalog = BoatBasePortCatalog::fromBoats(self::boats());

        self::assertSame(
            [
                ['id' => 7, 'value' => '7', 'text' => 'Club Náutico Sant Antoni', 'boats' => 1],
                ['id' => 2, 'value' => '2', 'text' => 'Marina Ibiza', 'boats' => 2],
            ],
            $catalog
        );
    }

    public function testItSearchesByName(): void
    {
        $catalog = BoatBasePortCatalog::fromBoats(self::boats());

        self::assertSame([2], array_column(BoatBasePortCatalog::search($catalog, 'ibiza'), 'id'));
        self::assertSame([7], array_column(BoatBasePortCatalog::search($catalog, 'NÁUTICO'), 'id'));
        self::assertSame([7, 2], array_column(BoatBasePortCatalog::search($catalog, ''), 'id'));
        self::assertSame([], BoatBasePortCatalog::search($catalog, 'palma'));
    }

    public function testItPicksSavedPorts(): void
    {
        $catalog = BoatBasePortCatalog::fromBoats(self::boats());

        self::assertSame([2], array_column(BoatBasePortCatalog::pick($catalog, ['2']), 'id'));
        self::assertSame([7, 2], array_column(BoatBasePortCatalog::pick($catalog, [7, 2]), 'id'));
        self::assertSame([], BoatBasePortCatalog::pick($catalog, ['0', 'abc']));
    }

    public function testAnEmptyCatalogueIsEmpty(): void
    {
        self::assertSame([], BoatBasePortCatalog::fromBoats([]));
    }
}
