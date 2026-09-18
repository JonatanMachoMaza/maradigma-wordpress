<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\ArchiveScope;
use PHPUnit\Framework\TestCase;

final class ArchiveScopeTest extends TestCase
{
    private const SECRET = 'unit-test-secret';

    /** @var list<string> */
    private const KEYS = ['boat_type_id', 'builders', 'ids_gi', 'tags', 'featured', 'image_token'];

    public function testExtractKeepsOnlyAllowedNonEmptyNonDefaultAttributes(): void
    {
        $scope = ArchiveScope::extract(
            [
                'tags'         => '3,7',
                'boat_type_id' => ' 4 ',
                'builders'     => '',
                'featured'     => '0',
                'ids_gi'       => null,
                'limit_services' => '12',
                'card'         => 'clean-grid',
            ],
            self::KEYS,
            ['featured' => '0']
        );

        self::assertSame(['boat_type_id' => '4', 'tags' => '3,7'], $scope);
    }

    public function testExtractIgnoresNonScalarValues(): void
    {
        self::assertSame([], ArchiveScope::extract(['builders' => ['1', '2']], self::KEYS));
    }

    public function testExtractCastsScalarsToStrings(): void
    {
        self::assertSame(['featured' => '1'], ArchiveScope::extract(['featured' => true], self::KEYS));
    }

    public function testEncodedScopeRoundTrips(): void
    {
        $scope = ['boat_type_id' => '4', 'ids_gi' => '304,305'];

        $token = ArchiveScope::encode($scope, self::SECRET);

        self::assertNotSame('', $token);
        self::assertSame($scope, ArchiveScope::decode($token, self::SECRET, self::KEYS));
    }

    public function testEncodingIsStableRegardlessOfInputOrder(): void
    {
        $a = ArchiveScope::encode(ArchiveScope::extract(['tags' => '1', 'boat_type_id' => '4'], self::KEYS), self::SECRET);
        $b = ArchiveScope::encode(ArchiveScope::extract(['boat_type_id' => '4', 'tags' => '1'], self::KEYS), self::SECRET);

        self::assertSame($a, $b);
    }

    public function testTokenIsSafeToPutInAQueryString(): void
    {
        $token = ArchiveScope::encode(['builders' => 'Ñandú / Sea+Ray?'], self::SECRET);

        self::assertMatchesRegularExpression('/^v1\.[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $token);
    }

    public function testEmptyScopeOrSecretProducesNoToken(): void
    {
        self::assertSame('', ArchiveScope::encode([], self::SECRET));
        self::assertSame('', ArchiveScope::encode(['boat_type_id' => '4'], ''));
    }

    public function testTokenSignedWithAnotherSecretIsRejected(): void
    {
        $token = ArchiveScope::encode(['boat_type_id' => '4'], 'another-secret');

        self::assertSame([], ArchiveScope::decode($token, self::SECRET, self::KEYS));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $token = ArchiveScope::encode(['boat_type_id' => '4'], self::SECRET);
        [$version, , $signature] = explode('.', $token);

        $forged = $version . '.' . rtrim(strtr(base64_encode('{"boat_type_id":"5"}'), '+/', '-_'), '=') . '.' . $signature;

        self::assertSame([], ArchiveScope::decode($forged, self::SECRET, self::KEYS));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $token = ArchiveScope::encode(['boat_type_id' => '4'], self::SECRET);

        self::assertSame([], ArchiveScope::decode(substr($token, 0, -1) . '0', self::SECRET, self::KEYS));
    }

    /**
     * @dataProvider malformedTokenProvider
     */
    public function testMalformedTokensAreRejected(string $token): void
    {
        self::assertSame([], ArchiveScope::decode($token, self::SECRET, self::KEYS));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function malformedTokenProvider(): array
    {
        return [
            'empty'             => [''],
            'garbage'           => ['not-a-token'],
            'missing signature' => ['v1.eyJib2F0X3R5cGVfaWQiOiI0In0'],
            'unknown version'   => ['v9.eyJib2F0X3R5cGVfaWQiOiI0In0.' . str_repeat('a', 64)],
            'too many parts'    => ['v1.a.b.c'],
            'oversized'         => ['v1.' . str_repeat('a', 7000) . '.' . str_repeat('a', 64)],
        ];
    }

    public function testDecodeDropsKeysThatAreNotAllowed(): void
    {
        $token = ArchiveScope::encode(['boat_type_id' => '4', 'search_own_managment' => '0'], self::SECRET);

        self::assertSame(['boat_type_id' => '4'], ArchiveScope::decode($token, self::SECRET, self::KEYS));
    }

    public function testOversizedScopeProducesNoToken(): void
    {
        self::assertSame('', ArchiveScope::encode(['ids_gi' => str_repeat('1,', 4000)], self::SECRET));
    }
}
