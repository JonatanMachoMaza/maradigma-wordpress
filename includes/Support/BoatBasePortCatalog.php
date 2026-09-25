<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Pure rules that turn boat search rows into the list of base ports a listing
 * can be filtered by.
 *
 * GET /boat-base-ports lists every port of the system, including ports with no
 * boats in this catalogue, so the list is built from the boats themselves: each
 * row of /search-services carries its own base port. Those are the ports that
 * can actually return boats, and each one filters the search with
 * boat_base_port={id}.
 *
 * @phpstan-type BoatBasePort array{id:int,value:string,text:string,boats:int}
 */
final class BoatBasePortCatalog
{
    /**
     * Builds the base port list from boat rows, counting boats per port.
     *
     * @param array<int|string,mixed> $boats Boat rows from /search-services.
     * @return list<BoatBasePort>
     */
    public static function fromBoats(array $boats): array
    {
        $catalog = [];

        foreach ($boats as $boat) {
            if (!\is_array($boat)) {
                continue;
            }

            $port = self::normalizeRow($boat);
            if ($port === null) {
                continue;
            }

            if (isset($catalog[$port['id']])) {
                ++$catalog[$port['id']]['boats'];
                continue;
            }

            $catalog[$port['id']] = $port;
        }

        $catalog = \array_values($catalog);

        \usort($catalog, static function (array $a, array $b): int {
            return [self::sortableText($a['text']), $a['id']] <=> [self::sortableText($b['text']), $b['id']];
        });

        return $catalog;
    }

    /**
     * Keeps the ports whose name contains the search text.
     *
     * @param list<BoatBasePort> $catalog
     * @return list<BoatBasePort>
     */
    public static function search(array $catalog, string $term): array
    {
        $term = self::sortableText($term);
        if ($term === '') {
            return $catalog;
        }

        return \array_values(\array_filter(
            $catalog,
            static fn(array $port): bool => \str_contains(self::sortableText($port['text']), $term)
        ));
    }

    /**
     * Keeps the ports whose id is in the list (for showing a saved value).
     *
     * @param list<BoatBasePort> $catalog
     * @param list<int|string>   $ids
     * @return list<BoatBasePort>
     */
    public static function pick(array $catalog, array $ids): array
    {
        $wanted = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $wanted[$id] = true;
            }
        }

        return \array_values(\array_filter(
            $catalog,
            static fn(array $port): bool => isset($wanted[$port['id']])
        ));
    }

    /**
     * @param array<string,mixed> $boat
     * @return BoatBasePort|null
     */
    private static function normalizeRow(array $boat): ?array
    {
        $id = $boat['boat_base_port'] ?? null;
        $id = \is_numeric($id) ? (int) $id : 0;
        if ($id <= 0) {
            return null;
        }

        $text = \trim((string) ($boat['boat_base_port_name'] ?? ''));
        if ($text === '') {
            return null;
        }

        return [
            'id'    => $id,
            'value' => (string) $id,
            'text'  => $text,
            'boats' => 1,
        ];
    }

    private static function sortableText(string $text): string
    {
        $text = \trim($text);

        return \function_exists('mb_strtolower') ? \mb_strtolower($text, 'UTF-8') : \strtolower($text);
    }
}
