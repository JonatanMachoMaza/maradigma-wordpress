<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\SelectedBoats;
use PHPUnit\Framework\TestCase;

final class SelectedBoatsTest extends TestCase
{
    public function testItKeepsOnlyTheSelectedBoatsInApiOrder(): void
    {
        $rows = [
            ['id' => 10, 'boat_id_builder' => 1],
            ['id' => 20, 'boat_id_builder' => 2],
            ['id_gi' => '30', 'boat_id_builder' => 2],
            'not a row',
            ['id' => 20, 'boat_id_builder' => 2],
            ['id_group_item' => 40],
        ];

        self::assertSame([20, 30, 40], array_map(
            [SelectedBoats::class, 'idOf'],
            SelectedBoats::pick($rows, [40, 30, 20, 99])
        ));
        self::assertSame([], SelectedBoats::pick($rows, []));
    }

    public function testItSortsByTheAuthorsList(): void
    {
        $rows = [['id' => 20], ['id' => 30], ['id' => 40]];

        self::assertSame(
            [40, 20, 30],
            array_map([SelectedBoats::class, 'idOf'], SelectedBoats::sortByList($rows, [40, 20, 30]))
        );
    }

    public function testItPagesTheMatches(): void
    {
        $matches = [
            ['id' => 1, 'boat_id_builder' => 7],
            ['id' => 2, 'boat_id_builder' => 7],
            ['id' => 3, 'boat_id_builder' => 9],
        ];

        $result = SelectedBoats::result($matches, 2, 2, ['min_price' => 100, 'total_results' => 250]);

        self::assertTrue($result['success']);
        self::assertSame([['id' => 3, 'boat_id_builder' => 9]], $result['data']['search_result']);
        self::assertSame(3, $result['data']['total_results']);
        self::assertSame([7, 9], $result['data']['available_boat_id_builders']);
        self::assertSame(100, $result['data']['min_price']);
    }

    public function testAnEmptySelectionGivesNoBoats(): void
    {
        $result = SelectedBoats::result([], 0, 10, []);

        self::assertSame([], $result['data']['search_result']);
        self::assertSame(0, $result['data']['total_results']);
    }
}
