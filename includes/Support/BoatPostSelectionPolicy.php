<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Pure rules that choose one WordPress post when several posts are bound to
 * the same Maradigma boat in the same language.
 *
 * The boat sync and the public boat listings both use these rules, so a
 * duplicate left behind by an earlier sync can never make them disagree.
 *
 * Candidate shape:
 * - id:          WordPress post ID.
 * - lang:        Language code assigned by the multilingual plugin ('' when none).
 * - status:      WordPress post status.
 * - explicit:    True when an editor bound the post to the boat by hand (page
 *                boat ID meta) instead of the sync creating it.
 * - curated:     True when an editor made or customized the post (custom
 *                layout or no-sync flag, or not created by the sync).
 * - retired:     True when a duplicate cleanup unpublished the post in favour
 *                of another one.
 * - linked:      True when the post shares a translation group with another
 *                post bound to the same boat.
 * - has_content: True when the post has layout content (post_content or builder data).
 * - date:        Post date in 'Y-m-d H:i:s' format (GMT preferred).
 *
 * @phpstan-type BoatPostCandidate array{id:int,lang:string,status:string,explicit:bool,curated:bool,retired:bool,linked:bool,has_content:bool,date:string}
 */
final class BoatPostSelectionPolicy
{
    /** @var array<string,int> */
    private const STATUS_RANK = [
        'publish' => 5,
        'future'  => 4,
        'private' => 3,
        'pending' => 2,
        'draft'   => 1,
    ];

    /**
     * Normalizes a language code: lowercase, trimmed, '_' written as '-'.
     *
     * The region is kept, so 'pt-br' and 'pt-pt' stay different languages.
     */
    public static function normalizeLanguage(string $language): string
    {
        return \str_replace('_', '-', \strtolower(\trim($language)));
    }

    /**
     * Returns the primary subtag of a language code ('pt' for 'pt-br').
     */
    public static function primaryLanguage(string $language): string
    {
        $language = self::normalizeLanguage($language);
        $dash = \strpos($language, '-');

        return $dash === false ? $language : \substr($language, 0, $dash);
    }

    /**
     * Returns the candidates assigned to exactly one language.
     *
     * @param list<BoatPostCandidate> $candidates
     * @return list<BoatPostCandidate>
     */
    public static function filterLanguage(array $candidates, string $language): array
    {
        $language = self::normalizeLanguage($language);

        return \array_values(\array_filter(
            $candidates,
            static fn(array $candidate): bool => self::normalizeLanguage($candidate['lang']) === $language
        ));
    }

    /**
     * Returns the candidates that only share the primary subtag of a language
     * ('en' page, 'en-gb' post), for public links without an exact match.
     *
     * @param list<BoatPostCandidate> $candidates
     * @return list<BoatPostCandidate>
     */
    public static function filterPrimaryLanguage(array $candidates, string $language): array
    {
        if (self::normalizeLanguage($language) === '') {
            return [];
        }

        $primary = self::primaryLanguage($language);

        return \array_values(\array_filter(
            $candidates,
            static fn(array $candidate): bool => $candidate['lang'] !== '' && self::primaryLanguage($candidate['lang']) === $primary
        ));
    }

    /**
     * Returns only published candidates, for public links.
     *
     * @param list<BoatPostCandidate> $candidates
     * @return list<BoatPostCandidate>
     */
    public static function filterPublished(array $candidates): array
    {
        return \array_values(\array_filter(
            $candidates,
            static fn(array $candidate): bool => $candidate['status'] === 'publish'
        ));
    }

    /**
     * Sorts candidates from the best to the worst choice.
     *
     * Order: posts a duplicate cleanup retired go last; otherwise posts an
     * editor bound by hand, then published posts an editor made or customized,
     * then the preferred post (the translation already linked to the boat's
     * source-language post), then posts in a translation group, then the most
     * public status, then posts with layout content, then the newest post.
     *
     * A customized post only jumps ahead while it is published, so an
     * unpublished copy can never displace the published page of a language.
     *
     * @param list<BoatPostCandidate> $candidates
     * @return list<BoatPostCandidate>
     */
    public static function rank(array $candidates, int $preferredId = 0): array
    {
        \usort($candidates, static function (array $a, array $b) use ($preferredId): int {
            return self::sortKey($b, $preferredId) <=> self::sortKey($a, $preferredId);
        });

        return $candidates;
    }

    /**
     * Returns the ID of the best candidate, or 0 when there is none.
     *
     * @param list<BoatPostCandidate> $candidates
     */
    public static function pick(array $candidates, int $preferredId = 0): int
    {
        $ranked = self::rank($candidates, $preferredId);

        return $ranked === [] ? 0 : $ranked[0]['id'];
    }

    /**
     * Lists, per language, the candidates that duplicate the kept post.
     *
     * @param list<BoatPostCandidate> $candidates
     * @param array<string,int>       $keptByLanguage language => kept post ID
     * @return array<string,array{keep:int,duplicates:list<int>}>
     */
    public static function duplicatesByLanguage(array $candidates, array $keptByLanguage): array
    {
        $out = [];

        foreach ($keptByLanguage as $language => $keptId) {
            $keptId = (int) $keptId;
            $language = self::normalizeLanguage((string) $language);
            if ($keptId <= 0) {
                continue;
            }

            $duplicates = [];
            foreach (self::filterLanguage($candidates, $language) as $candidate) {
                if ($candidate['id'] !== $keptId) {
                    $duplicates[] = $candidate['id'];
                }
            }

            if ($duplicates !== []) {
                \sort($duplicates);
                $out[$language] = [
                    'keep'       => $keptId,
                    'duplicates' => $duplicates,
                ];
            }
        }

        return $out;
    }

    /**
     * @param BoatPostCandidate $candidate
     * @return array{0:int,1:int,2:int,3:int,4:int,5:int,6:int,7:int,8:string,9:int}
     */
    private static function sortKey(array $candidate, int $preferredId): array
    {
        return [
            $candidate['retired'] ? 0 : 1,
            $candidate['explicit'] ? 1 : 0,
            $candidate['curated'] && $candidate['status'] === 'publish' ? 1 : 0,
            $preferredId > 0 && $candidate['id'] === $preferredId ? 1 : 0,
            $candidate['linked'] ? 1 : 0,
            self::STATUS_RANK[$candidate['status']] ?? 0,
            $candidate['curated'] ? 1 : 0,
            $candidate['has_content'] ? 1 : 0,
            $candidate['date'],
            $candidate['id'],
        ];
    }
}
