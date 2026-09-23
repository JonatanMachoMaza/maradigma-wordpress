<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Restricts search results to a list of boat ids.
 *
 * POST /search-services has no id filter, so the plugin reads the filtered
 * search page by page and keeps the selected boats; the other filters,
 * availability and sorting still come from the API.
 */
final class SelectedBoats
{
    /**
     * Rows read per API call and the most calls one listing may make.
     */
    public const PAGE_SIZE = 100;
    public const MAX_PAGES = 20;

    /**
     * Most boats one listing may select.
     */
    public const MAX_IDS = 20;

    /**
     * Pages read when only a visitor picked the boat.
     */
    public const MAX_VISITOR_PAGES = 2;

    /**
     * @param array<string,mixed> $row
     */
    public static function idOf(array $row): int
    {
        $id = $row['id'] ?? $row['id_gi'] ?? $row['id_group_item'] ?? 0;

        return \is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Keeps the rows of the selected boats, in API order, without repeats.
     *
     * @param array<int|string,mixed> $rows
     * @param list<int> $boatIds
     * @return list<array<string,mixed>>
     */
    public static function pick(array $rows, array $boatIds): array
    {
        $wanted = \array_fill_keys($boatIds, true);
        $picked = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $id = self::idOf($row);
            if ($id > 0 && isset($wanted[$id]) && !isset($picked[$id])) {
                $picked[$id] = $row;
            }
        }

        return \array_values($picked);
    }

    /**
     * Orders rows as the author listed the boats.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<int> $boatIds
     * @return list<array<string,mixed>>
     */
    public static function sortByList(array $rows, array $boatIds): array
    {
        $position = \array_flip($boatIds);
        \usort($rows, static function (array $a, array $b) use ($position): int {
            return ($position[self::idOf($a)] ?? PHP_INT_MAX) <=> ($position[self::idOf($b)] ?? PHP_INT_MAX);
        });

        return $rows;
    }

    /**
     * Builds a search response for one listing page of the selected boats.
     *
     * @param list<array<string,mixed>> $matches
     * @param array<string,mixed> $data Data of the first API page (price range, etc.).
     * @return array{success:true,data:array<string,mixed>}
     */
    public static function result(array $matches, int $offset, int $limit, array $data): array
    {
        $builders = [];
        foreach ($matches as $row) {
            $builderId = $row['boat_id_builder'] ?? null;
            if (\is_numeric($builderId) && (int) $builderId > 0) {
                $builders[(int) $builderId] = (int) $builderId;
            }
        }

        $data['search_result'] = \array_slice($matches, \max(0, $offset), \max(1, $limit));
        $data['total_results'] = \count($matches);
        $data['available_boat_id_builders'] = \array_values($builders);
        $data['limit'] = $limit;
        $data['offset'] = $offset;

        return ['success' => true, 'data' => $data];
    }
}
