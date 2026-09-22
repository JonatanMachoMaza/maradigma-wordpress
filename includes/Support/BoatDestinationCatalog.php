<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Pure rules that turn boat search rows into the list of destinations an
 * editor can filter a boat listing by.
 *
 * The Maradigma API has no destination catalogue endpoint, but every boat row
 * of /search-services carries the destinations of its base port. Those are the
 * destinations that can actually return boats, so the list is built from them.
 * Each one filters the search with departure_location=destination:{id}.
 *
 * @phpstan-type BoatDestination array{id:int,value:string,text:string,place_type:string,subtitle:string,boats:int}
 */
final class BoatDestinationCatalog
{
    /** Display order of place types: commercial destinations first. */
    private const TYPE_PRIORITY = [
        'island'              => 10,
        'archipelago'         => 20,
        'locality'            => 30,
        'sublocality'         => 40,
        'neighborhood'        => 50,
        'region'              => 60,
        'administrative_area' => 70,
        'country'             => 80,
        'marina'              => 90,
        'port'                => 100,
    ];

    /**
     * Builds the destination list from boat rows, counting boats per destination.
     *
     * @param array<int|string,mixed> $boats Boat rows from /search-services.
     * @return list<BoatDestination>
     */
    public static function fromBoats(array $boats): array
    {
        $catalog = [];

        foreach ($boats as $boat) {
            if (!\is_array($boat)) {
                continue;
            }

            $rows = \is_array($boat['destinations'] ?? null) ? (array) $boat['destinations'] : [];
            if ($rows === [] && \is_array($boat['destination'] ?? null)) {
                $rows = [(array) $boat['destination']];
            }

            $seenForBoat = [];

            foreach ($rows as $row) {
                $destination = self::normalizeRow($row);
                if ($destination === null || isset($seenForBoat[$destination['id']])) {
                    continue;
                }

                $seenForBoat[$destination['id']] = true;

                if (isset($catalog[$destination['id']])) {
                    ++$catalog[$destination['id']]['boats'];
                    continue;
                }

                $catalog[$destination['id']] = $destination;
            }
        }

        $catalog = \array_values($catalog);

        \usort($catalog, static function (array $a, array $b): int {
            return [self::typePriority($a['place_type']), self::sortableText($a['text']), $a['id']]
                <=> [self::typePriority($b['place_type']), self::sortableText($b['text']), $b['id']];
        });

        return $catalog;
    }

    /**
     * Keeps the destinations whose name or subtitle contains the search text.
     *
     * @param list<BoatDestination> $catalog
     * @return list<BoatDestination>
     */
    public static function search(array $catalog, string $term): array
    {
        $term = self::sortableText($term);
        if ($term === '') {
            return $catalog;
        }

        return \array_values(\array_filter(
            $catalog,
            static fn(array $destination): bool => \str_contains(self::sortableText($destination['text']), $term)
                || \str_contains(self::sortableText($destination['subtitle']), $term)
        ));
    }

    /**
     * Keeps the destinations whose token is in the list (for showing saved values).
     *
     * @param list<BoatDestination> $catalog
     * @param list<string>          $tokens  Tokens or bare destination IDs.
     * @return list<BoatDestination>
     */
    public static function pick(array $catalog, array $tokens): array
    {
        $wanted = [];
        foreach ($tokens as $token) {
            $normalized = Sanitizer::normalizeLocationToken((string) $token);
            if ($normalized !== null) {
                $wanted[$normalized] = true;
            }
        }

        return \array_values(\array_filter(
            $catalog,
            static fn(array $destination): bool => isset($wanted[$destination['value']])
        ));
    }

    /**
     * @return BoatDestination|null
     */
    private static function normalizeRow(mixed $row): ?array
    {
        if (!\is_array($row)) {
            return null;
        }

        $entityType = \strtolower(\trim((string) ($row['entity_type'] ?? 'destination')));
        $id = (int) ($row['id'] ?? 0);
        if ($entityType !== 'destination' || $id <= 0) {
            return null;
        }

        $text = '';
        foreach (['text', 'name', 'slug'] as $key) {
            $text = \trim((string) ($row[$key] ?? ''));
            if ($text !== '') {
                break;
            }
        }

        if ($text === '') {
            return null;
        }

        return [
            'id'         => $id,
            'value'      => 'destination:' . $id,
            'text'       => $text,
            'place_type' => \strtolower(\trim((string) ($row['place_type'] ?? ''))),
            'subtitle'   => \trim((string) ($row['subtitle'] ?? '')),
            'boats'      => 1,
        ];
    }

    private static function typePriority(string $placeType): int
    {
        return self::TYPE_PRIORITY[$placeType] ?? 1000;
    }

    private static function sortableText(string $text): string
    {
        $text = \trim($text);

        return \function_exists('mb_strtolower') ? \mb_strtolower($text, 'UTF-8') : \strtolower($text);
    }
}
