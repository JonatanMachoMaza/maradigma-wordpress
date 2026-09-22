<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatPostSelectionPolicy;
use PHPUnit\Framework\TestCase;

final class BoatPostSelectionPolicyTest extends TestCase
{
    public function testTranslationGroupMemberWinsOverNewerOrphan(): void
    {
        // Sync double run: the linked set is newer, the orphan set is older and empty.
        $candidates = [
            self::candidate(10, 'en', 'publish', false, false, '2026-09-17 09:33:10'),
            self::candidate(20, 'en', 'publish', true, true, '2026-09-17 09:48:02'),
        ];

        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testLinkedPostWinsEvenWhenOrphanIsNewer(): void
    {
        $candidates = [
            self::candidate(10, 'en', 'publish', true, true, '2026-09-17 09:33:10'),
            self::candidate(20, 'en', 'publish', false, true, '2026-09-17 09:48:02'),
        ];

        self::assertSame(10, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testPreferredTranslationWinsOverLinkStatusAndContent(): void
    {
        $candidates = [
            self::candidate(10, 'en', 'publish', true, true, '2026-09-18 10:00:00'),
            self::candidate(20, 'en', 'draft', false, false, '2026-09-17 09:48:02'),
        ];

        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates, 20));
    }

    public function testUnknownPreferredIdFallsBackToTheRules(): void
    {
        $candidates = [
            self::candidate(10, 'en', 'publish', false, false, '2026-09-17 09:33:10'),
            self::candidate(20, 'en', 'publish', true, true, '2026-09-17 09:48:02'),
        ];

        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates, 999));
    }

    public function testNewestPostWithContentWinsBetweenUnlinkedPosts(): void
    {
        $candidates = [
            self::candidate(30, 'de', 'publish', false, false, '2026-09-19 08:00:00'),
            self::candidate(10, 'de', 'publish', false, true, '2026-09-17 09:33:10'),
            self::candidate(20, 'de', 'publish', false, true, '2026-09-17 09:48:02'),
        ];

        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testPublishedPostWinsOverDraft(): void
    {
        $candidates = [
            self::candidate(10, 'fr', 'draft', false, true, '2026-09-19 08:00:00'),
            self::candidate(20, 'fr', 'publish', false, true, '2026-09-17 09:48:02'),
        ];

        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testEqualCandidatesAreBrokenByHighestId(): void
    {
        $candidates = [
            self::candidate(21, 'it', 'publish', false, true, '2026-09-17 09:48:02'),
            self::candidate(22, 'it', 'publish', false, true, '2026-09-17 09:48:02'),
        ];

        self::assertSame(22, BoatPostSelectionPolicy::pick($candidates));
        self::assertSame(22, BoatPostSelectionPolicy::pick(\array_reverse($candidates)));
    }

    public function testEmptyCandidateListPicksNothing(): void
    {
        self::assertSame(0, BoatPostSelectionPolicy::pick([]));
    }

    public function testLanguageFilterNormalizesCaseAndSeparators(): void
    {
        $candidates = [
            self::candidate(1, 'en_GB', 'publish', false, true, '2026-09-17 09:33:10'),
            self::candidate(2, 'es', 'publish', false, true, '2026-09-17 09:33:10'),
            self::candidate(3, 'EN', 'publish', false, true, '2026-09-17 09:33:10'),
        ];

        self::assertSame([1], \array_column(BoatPostSelectionPolicy::filterLanguage($candidates, 'en-gb'), 'id'));
        self::assertSame([3], \array_column(BoatPostSelectionPolicy::filterLanguage($candidates, 'en'), 'id'));
    }

    public function testPublishedFilterDropsNonPublicStatuses(): void
    {
        $candidates = [
            self::candidate(1, 'en', 'publish', false, true, '2026-09-17 09:33:10'),
            self::candidate(2, 'en', 'draft', false, true, '2026-09-17 09:33:10'),
            self::candidate(3, 'en', 'private', false, true, '2026-09-17 09:33:10'),
            self::candidate(4, 'en', 'pending', false, true, '2026-09-17 09:33:10'),
        ];

        self::assertSame([1], \array_column(BoatPostSelectionPolicy::filterPublished($candidates), 'id'));
    }

    public function testDuplicatesAreReportedPerLanguageAgainstTheKeptPost(): void
    {
        $candidates = [
            self::candidate(1, 'es', 'publish', true, true, '2026-09-17 09:33:10'),
            self::candidate(2, 'en', 'publish', false, false, '2026-09-17 09:33:11'),
            self::candidate(3, 'en', 'publish', true, true, '2026-09-17 09:48:02'),
            self::candidate(4, 'de', 'publish', true, true, '2026-09-17 09:48:03'),
            self::candidate(5, 'en', 'draft', false, false, '2026-09-16 09:00:00'),
        ];

        self::assertSame(
            ['en' => ['keep' => 3, 'duplicates' => [2, 5]]],
            BoatPostSelectionPolicy::duplicatesByLanguage($candidates, ['es' => 1, 'en' => 3, 'de' => 4])
        );
    }

    public function testDuplicatesIgnoreLanguagesWithoutAKeptPost(): void
    {
        $candidates = [
            self::candidate(2, 'nl', 'publish', false, false, '2026-09-17 09:33:11'),
            self::candidate(3, 'nl', 'publish', false, true, '2026-09-17 09:48:02'),
        ];

        self::assertSame([], BoatPostSelectionPolicy::duplicatesByLanguage($candidates, ['nl' => 0, 'ca' => 9]));
    }

    public function testPageBoundByAnEditorWinsOverTheSyncedPost(): void
    {
        // A page an editor bound to the boat by hand is the boat's page in that language.
        $candidates = [
            self::candidate(2417, 'es', 'publish', true, true, '2026-09-17 09:48:02'),
            self::candidate(2, 'es', 'publish', false, true, '2025-12-16 16:07:24', true),
        ];

        self::assertSame(2, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testCustomizedOrphanWinsOverTheLinkedTemplateCopy(): void
    {
        // An editor customized the orphan the old listing linked; it stays the boat's page.
        $candidates = [
            self::candidate(20, 'en', 'publish', true, true, '2026-09-17 09:48:02'),
            self::candidate(10, 'en', 'publish', false, true, '2026-09-17 09:33:10', false, true),
        ];

        self::assertSame(10, BoatPostSelectionPolicy::pick($candidates, 20));
    }

    public function testAnchorDecidesBetweenTwoCustomizedPosts(): void
    {
        $candidates = [
            self::candidate(20, 'en', 'publish', true, true, '2026-09-18 09:48:02', false, true),
            self::candidate(10, 'en', 'publish', false, true, '2026-09-17 09:33:10', false, true),
        ];

        self::assertSame(10, BoatPostSelectionPolicy::pick($candidates, 10));
    }

    public function testRegionalLanguagesStayApart(): void
    {
        $candidates = [
            self::candidate(1, 'pt-br', 'publish', true, true, '2026-09-17 09:33:10'),
            self::candidate(2, 'pt-pt', 'publish', true, true, '2026-09-17 09:33:11'),
            self::candidate(3, 'zh-hans', 'publish', true, true, '2026-09-17 09:33:12'),
            self::candidate(4, 'zh_Hant', 'publish', true, true, '2026-09-17 09:33:13'),
        ];

        self::assertSame([1], \array_column(BoatPostSelectionPolicy::filterLanguage($candidates, 'pt-br'), 'id'));
        self::assertSame([4], \array_column(BoatPostSelectionPolicy::filterLanguage($candidates, 'zh-hant'), 'id'));
        self::assertSame(
            [],
            BoatPostSelectionPolicy::duplicatesByLanguage($candidates, ['pt-br' => 1, 'pt-pt' => 2, 'zh-hans' => 3, 'zh-hant' => 4])
        );
    }

    public function testPrimaryLanguageFilterMatchesAcrossRegions(): void
    {
        $candidates = [
            self::candidate(1, 'en-gb', 'publish', true, true, '2026-09-17 09:33:10'),
            self::candidate(2, 'es', 'publish', true, true, '2026-09-17 09:33:11'),
            self::candidate(3, '', 'publish', false, true, '2026-09-17 09:33:12'),
        ];

        self::assertSame([1], \array_column(BoatPostSelectionPolicy::filterPrimaryLanguage($candidates, 'en'), 'id'));
        self::assertSame([2], \array_column(BoatPostSelectionPolicy::filterPrimaryLanguage($candidates, 'es-mx'), 'id'));
        self::assertSame([], BoatPostSelectionPolicy::filterPrimaryLanguage($candidates, 'fr'));
    }

    public function testPrimaryLanguage(): void
    {
        self::assertSame('pt', BoatPostSelectionPolicy::primaryLanguage('pt_BR'));
        self::assertSame('es', BoatPostSelectionPolicy::primaryLanguage('ES'));
        self::assertSame('', BoatPostSelectionPolicy::primaryLanguage(''));
    }

    public function testCustomizedDraftDoesNotDisplaceThePublishedLinkedPost(): void
    {
        // An editor saved an unpublished copy in Elementor: the published page stays the boat's page.
        $candidates = [
            self::candidate(20, 'en', 'publish', true, true, '2026-09-17 09:48:02'),
            self::candidate(10, 'en', 'draft', false, true, '2026-09-18 09:33:10', false, true),
        ];

        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates, 20));
        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testRetiredDuplicateRanksLastEvenWhenCustomized(): void
    {
        $candidates = [
            self::candidate(10, 'en', 'publish', false, true, '2026-09-18 09:33:10', false, true, true),
            self::candidate(20, 'en', 'draft', false, false, '2026-09-17 09:48:02'),
        ];

        self::assertSame(20, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testRetiredDuplicateIsPickedWhenItIsTheOnlyPostLeft(): void
    {
        $candidates = [
            self::candidate(10, 'en', 'draft', false, true, '2026-09-18 09:33:10', false, false, true),
        ];

        self::assertSame(10, BoatPostSelectionPolicy::pick($candidates));
    }

    public function testPrimaryLanguageFilterFindsRegionalSiblingsOnly(): void
    {
        $candidates = [
            self::candidate(1, 'pt-br', 'publish', true, true, '2026-09-17 09:33:10'),
            self::candidate(2, 'es', 'publish', true, true, '2026-09-17 09:33:11'),
            self::candidate(3, '', 'publish', false, true, '2026-09-17 09:33:12'),
        ];

        self::assertSame([1], \array_column(BoatPostSelectionPolicy::filterPrimaryLanguage($candidates, 'pt'), 'id'));
        self::assertSame([1], \array_column(BoatPostSelectionPolicy::filterPrimaryLanguage($candidates, 'pt-pt'), 'id'));
        self::assertSame([], BoatPostSelectionPolicy::filterPrimaryLanguage($candidates, ''));
    }

    /**
     * @return array{id:int,lang:string,status:string,explicit:bool,curated:bool,retired:bool,linked:bool,has_content:bool,date:string}
     */
    private static function candidate(
        int $id,
        string $lang,
        string $status,
        bool $linked,
        bool $hasContent,
        string $date,
        bool $explicit = false,
        bool $curated = false,
        bool $retired = false
    ): array {
        return [
            'id'          => $id,
            'lang'        => $lang,
            'status'      => $status,
            'explicit'    => $explicit,
            'curated'     => $curated,
            'retired'     => $retired,
            'linked'      => $linked,
            'has_content' => $hasContent,
            'date'        => $date,
        ];
    }
}
