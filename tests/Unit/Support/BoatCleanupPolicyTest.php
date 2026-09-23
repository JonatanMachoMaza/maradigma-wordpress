<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\BoatCleanupPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoatCleanupPolicyTest extends TestCase
{
    /**
     * @param array<string,mixed> $response
     */
    #[DataProvider('goneProvider')]
    public function testItOnlyTrustsTheServiceNotFoundAnswers(int $status, array $response, string $expected): void
    {
        self::assertSame($expected, BoatCleanupPolicy::goneReason($status, $response));
    }

    /**
     * @return iterable<string,array{0:int,1:array<string,mixed>,2:string}>
     */
    public static function goneProvider(): iterable
    {
        yield 'not found' => [404, ['status' => 'error', 'message' => 'Service not found'], BoatCleanupPolicy::REASON_NOT_FOUND];
        yield 'deleted' => [404, ['status' => 'error', 'message' => 'Service deleted'], BoatCleanupPolicy::REASON_DELETED];
        yield 'not published' => [404, ['status' => 'error', 'message' => 'Service is not published'], BoatCleanupPolicy::REASON_NOT_PUBLISHED];
        yield 'boat returned' => [200, ['status' => 'success', 'data' => ['id' => 1]], ''];
        yield 'unknown route' => [404, ['status' => 'error', 'message' => 'Endpoint not found.'], ''];
        yield 'bad key' => [401, ['status' => 'error', 'message' => 'Invalid API Key.'], ''];
        yield 'message with a server error' => [500, ['status' => 'error', 'message' => 'Service not found'], ''];
        yield 'no request completed' => [0, ['status' => 'error', 'message' => 'Service not found'], ''];
        yield 'not an error body' => [404, ['message' => 'Service not found'], ''];
    }

    public function testItNeverRemovesAnUnpublishedBoatPage(): void
    {
        self::assertSame('draft', BoatCleanupPolicy::effectiveAction('trash', BoatCleanupPolicy::REASON_NOT_PUBLISHED));
        self::assertSame('draft', BoatCleanupPolicy::effectiveAction('delete', BoatCleanupPolicy::REASON_NOT_PUBLISHED));
        self::assertSame('draft', BoatCleanupPolicy::effectiveAction('draft', BoatCleanupPolicy::REASON_NOT_PUBLISHED));
        self::assertSame('trash', BoatCleanupPolicy::effectiveAction('trash', BoatCleanupPolicy::REASON_NOT_FOUND));
        self::assertSame('delete', BoatCleanupPolicy::effectiveAction('delete', BoatCleanupPolicy::REASON_DELETED));
    }

    #[DataProvider('massProvider')]
    public function testItBlocksRemovingMostOfTheCatalogue(int $local, int $missing, bool $expected): void
    {
        self::assertSame($expected, BoatCleanupPolicy::isMassRemoval($local, $missing));
    }

    /**
     * @return iterable<string,array{0:int,1:int,2:bool}>
     */
    public static function massProvider(): iterable
    {
        yield 'tiny site' => [2, 2, false];
        yield 'one boat gone' => [10, 1, false];
        yield 'half of the boats' => [10, 5, false];
        yield 'most of a small fleet' => [5, 3, true];
        yield 'another tenant' => [100, 100, true];
        yield 'nothing missing' => [8, 0, false];
    }

    #[DataProvider('idProvider')]
    public function testItOnlyChecksNumericIds(string $id, bool $expected): void
    {
        self::assertSame($expected, BoatCleanupPolicy::isNumericBoatId($id));
    }

    /**
     * @return iterable<string,array{0:string,1:bool}>
     */
    public static function idProvider(): iterable
    {
        yield 'id' => ['2410', true];
        yield 'leading zero' => ['0123', false];
        yield 'exponent' => ['1e3', false];
        yield 'slug' => ['pardo-yachts-38', false];
        yield 'empty' => ['', false];
        yield 'zero' => ['0', false];
    }
}
