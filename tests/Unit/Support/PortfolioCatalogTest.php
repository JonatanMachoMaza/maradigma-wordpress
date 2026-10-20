<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\ExternalApiClient;
use Maradigma\Cache;
use Maradigma\Support\PortfolioCatalog;
use Maradigma\Support\Sanitizer;
use Maradigma\ShortcodeRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PortfolioCatalogTest extends TestCase
{
    public function testReadsAllPagesUsingReturnedPageSizeInApiOrder(): void
    {
        $calls = [];
        $rows = PortfolioCatalog::readPages(static function (int $limit, int $offset) use (&$calls): array {
            $calls[] = $offset;
            return ['status' => 'success', 'data' => array_slice([['id' => 9], ['id' => 3], ['id' => 7]], $offset, 2),
                'pagination' => ['limit' => 2, 'offset' => $offset]];
        });
        self::assertSame([0, 2], $calls);
        self::assertSame([9, 3, 7], array_column($rows, 'id'));
    }

    public function testLaterPageFailureDoesNotReturnPartialMemberships(): void
    {
        $this->expectException(RuntimeException::class);
        PortfolioCatalog::readPages(static fn (int $limit, int $offset): array => $offset === 0
            ? ['status' => 'success', 'data' => [['id' => 1]], 'pagination' => ['limit' => 1, 'offset' => 0]]
            : ['status' => 'error', 'data' => []]);
    }

    public function testFiltersPublishedServicesThenPaginatesInPortfolioOrderWithoutTwentyBoatCap(): void
    {
        $client = $this->client();
        $result = $client->getBoatsList(['portfolio' => PortfolioApiFixture::UUID, 'limit_services' => 2, 'offset_services' => 1, 'term' => 'summer']);
        self::assertTrue($result['success']);
        self::assertSame([101, 1], array_column($result['data']['search_result'], 'id'));
        self::assertSame(3, $result['data']['total_results']);
        self::assertSame([0, 100, 200], $client->serviceOffsets);
        self::assertSame([0, 100], $client->itemOffsets);
        self::assertSame('summer', $client->lastFilters['term']);
        self::assertArrayNotHasKey('portfolio', $client->lastFilters);
    }

    public function testExplicitOrderingUsesServiceOrder(): void
    {
        $result = $this->client()->getBoatsList(['portfolio' => PortfolioApiFixture::UUID, 'order_by' => 1]);
        self::assertSame([1, 101, 201], array_column($result['data']['search_result'], 'id'));
    }

    public function testPortfolioCanDisplayMoreThanTwentyPublishedBoats(): void
    {
        $client = $this->client();
        $client->memberIds = range(120, 1);
        $result = $client->getBoatsList(['portfolio' => PortfolioApiFixture::UUID, 'limit_services' => 120]);
        self::assertSame(120, $result['data']['total_results']);
        self::assertSame(range(120, 1), array_column($result['data']['search_result'], 'id'));
    }

    public function testVisitorSelectionBeyondTwoPagesStillResolvesAndKeepsPortfolioOrder(): void
    {
        $client = $this->client();
        $client->memberIds = range(220, 1);
        $result = $client->getBoatsList(['portfolio' => PortfolioApiFixture::UUID, 'ids_gi' => [1, 220]]);
        self::assertSame([220, 1], array_column($result['data']['search_result'], 'id'));
        self::assertSame(2, $result['data']['total_results']);
        self::assertArrayNotHasKey('ids_gi', $client->lastFilters);
    }

    public function testSelectionOutsidePortfolioAndExplicitEmptySelectionStayEmpty(): void
    {
        foreach ([[9999], []] as $ids) {
            $client = $this->client();
            $result = $client->getBoatsList(['portfolio' => PortfolioApiFixture::UUID, 'ids_gi' => $ids]);
            self::assertTrue($result['success']);
            self::assertSame([], $result['data']['search_result']);
            self::assertSame([], $client->serviceOffsets);
        }
    }

    public function testFailureOnLaterServicePageDoesNotShowPartialResults(): void
    {
        $client = $this->client();
        $client->mode = 'later_service_failure';
        $result = $client->getBoatsList(['portfolio' => PortfolioApiFixture::UUID]);
        self::assertFalse($result['success']);
        self::assertSame([], $result['data']);
    }

    public function testEmptyPortfolioDoesNotRequestTheFullCatalogue(): void
    {
        $client = $this->client();
        $client->empty = true;
        $result = $client->getBoatsList(['portfolio' => PortfolioApiFixture::UUID]);
        self::assertTrue($result['success']);
        self::assertSame([], $result['data']['search_result']);
        self::assertSame([], $client->serviceOffsets);
    }

    public function testForeignMissingMalformedAndOtherGroupPortfoliosFailClosed(): void
    {
        foreach (['missing', 'other_group', 'service_failure', 'invalid'] as $mode) {
            $client = $this->client();
            $client->mode = $mode;
            $result = $client->getBoatsList(['portfolio' => $mode === 'invalid' ? '../12' : PortfolioApiFixture::UUID]);
            self::assertFalse($result['success'], $mode);
            self::assertSame([], $result['data'], $mode);
        }
    }

    public function testUuidAndInvalidValueSurviveNormalizationForFailClosedValidation(): void
    {
        $uuid = '3ac99911-5566-7788-99aa-bbccddeeff00';
        self::assertSame($uuid, PortfolioCatalog::normalizeUuid($uuid));
        self::assertNull(PortfolioCatalog::normalizeUuid('0'));
        foreach ([$uuid, 'invalid'] as $value) {
            $filters = Sanitizer::normalizeBoatsSearchAtts(['portfolio' => $value], ShortcodeRegistry::$DOC_SEARCH_BOATS_ATTRS);
            self::assertSame($value, $filters['portfolio']);
        }
    }

    public function testNumericPortfolioIdsAreRejectedBeforeApiCallsOrCacheReads(): void
    {
        foreach (['12', '0', '-12', '12.0', '1e2', '../12'] as $value) {
            self::assertNull(PortfolioCatalog::normalizeUuid($value));
            $client = $this->client();
            $result = $client->getBoatsList(['portfolio' => $value]);
            self::assertFalse($result['success']);
            self::assertSame([], $result['data']);
            $cached = (new Cache($client))->getBoatsList(['portfolio' => $value]);
            self::assertFalse($cached['success']);
            self::assertSame([], $cached['data']);
            self::assertSame(0, $client->portfolioReads);
            self::assertSame([], $client->serviceOffsets);
        }
    }

    public function testDirectPortfolioEndpointsRejectNumericIdentifiersBeforeTransport(): void
    {
        $client = new ExternalApiClient('https://example.test', 'public', 'secret', 'example.test', 'en_GB');
        foreach (['getGroupPortfolio', 'getGroupPortfolioItems'] as $method) {
            try {
                $client->$method('12');
                self::fail('Numeric portfolio identifier reached the transport.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Invalid portfolio UUID.', $e->getMessage());
            }
        }
    }

    public function testRendererRejectsNumericSelectionsBeforeLookingUpHtmlCache(): void
    {
        // Uninitialized dependencies make any cache or card lookup fail this test.
        $class = new \ReflectionClass(\Maradigma\Renderer::class);
        $renderer = $class->newInstanceWithoutConstructor();
        foreach (['12', 12, '0', '../12'] as $value) {
            self::assertSame('', $class->getMethod('renderBoatsArchive')->invoke($renderer, ['portfolio' => $value]));
        }
    }

    public function testEditorChoicesUseUuidWithoutFallbackToInternalId(): void
    {
        self::assertSame([['id' => PortfolioApiFixture::UUID, 'text' => 'Fleet']], PortfolioCatalog::choices([
            ['uuid' => PortfolioApiFixture::UUID, 'name' => 'Fleet'],
            ['id' => 12, 'uuid' => null, 'name' => 'Legacy'],
            ['id' => 99, 'name' => 'No UUID'],
            ['uuid' => '12', 'name' => 'Invalid'],
        ]));
    }

    public function testUnexpectedPortfolioAndMembershipUuidsFailClosed(): void
    {
        foreach (['wrong_uuid', 'wrong_membership_uuid', 'missing_membership_uuid'] as $mode) {
            $client = $this->client();
            $client->mode = $mode;
            $result = $client->getBoatsList(['portfolio' => PortfolioApiFixture::UUID]);
            self::assertFalse($result['success'], $mode);
            self::assertSame([], $result['data']);
            self::assertSame([], $client->serviceOffsets);
        }
    }

    public function testUuidCaseIsCanonicalAndTenantOverridesDoNotReachServiceSearch(): void
    {
        $uuid = '3ac99911-5566-7788-99aa-bbccddeeff00';
        self::assertSame($uuid, PortfolioCatalog::normalizeUuid(' ' . strtoupper($uuid) . ' '));
        $client = $this->client();
        $result = $client->getBoatsList(['portfolio' => strtoupper($uuid), 'id_tenant' => 999, 'tenant_id' => 999]);
        self::assertTrue($result['success']);
        self::assertArrayNotHasKey('id_tenant', $client->lastFilters);
        self::assertArrayNotHasKey('tenant_id', $client->lastFilters);
    }

    private function client(): PortfolioApiFixture
    {
        return new PortfolioApiFixture('https://example.test', 'public', 'secret', 'example.test', 'en_GB');
    }
}

/** API transport fixture: the production client still normalizes and resolves each search page. */
final class PortfolioApiFixture extends ExternalApiClient
{
    public const UUID = '12345678-1234-4321-8123-000000000012';
    public array $serviceOffsets = [];
    public array $itemOffsets = [];
    public array $lastFilters = [];
    public bool $empty = false;
    public ?array $memberIds = null;
    public string $mode = '';
    public int $portfolioReads = 0;

    public function getGroupPortfolio(string $identifier, array $query = []): array
    {
        $this->portfolioReads++;
        return ['status' => $this->mode === 'missing' ? 'error' : 'success', 'data' => [
            'uuid' => $this->mode === 'wrong_uuid' ? '12345678-1234-4321-8123-000000000099' : $identifier,
            'id_group' => $this->mode === 'other_group' ? 1 : 2,
            'is_bookings_enabled' => 0, 'is_pdf_enabled' => 1,
        ]];
    }

    public function getGroupPortfolioItems(string $identifier, array $query = []): array
    {
        $this->itemOffsets[] = $query['offset'];
        // More than one page, null positions and references to unpublished/filtered boats.
        $ids = $this->memberIds ?? array_merge([201, 101, 1], range(1000, 1098));
        $rows = $this->empty ? [] : array_map(fn (int $id): array => [
            'uuid_portfolio' => $this->mode === 'wrong_membership_uuid'
                ? '12345678-1234-4321-8123-000000000099'
                : ($this->mode === 'missing_membership_uuid' ? null : $identifier),
            'id_group' => 2, 'id_group_item' => $id, 'position' => null,
        ], $ids);
        return ['status' => 'success', 'data' => array_slice($rows, $query['offset'], $query['limit']),
            'pagination' => $query];
    }

    public function searchServices(array $filters): array
    {
        $this->lastFilters = $filters;
        $this->serviceOffsets[] = $filters['offset_services'];
        $rows = array_map(static fn (int $id): array => ['id' => $id, 'boat_id_builder' => 5], range(1, 220));
        $failed = $this->mode === 'service_failure'
            || ($this->mode === 'later_service_failure' && $filters['offset_services'] > 0);
        return ['status' => $failed ? 'error' : 'success', 'data' => [
            'search_result' => array_slice($rows, $filters['offset_services'], $filters['limit_services']),
            'total_results' => count($rows), 'offset' => $filters['offset_services'], 'limit' => $filters['limit_services'],
        ]];
    }
}
