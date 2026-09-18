<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\LocaleSwitcher;
use PHPUnit\Framework\TestCase;

final class LocaleSwitcherTest extends TestCase
{
    /**
     * @dataProvider languageProvider
     */
    public function testLocaleForLanguage(string $language, string $expected): void
    {
        self::assertSame($expected, LocaleSwitcher::localeForLanguage($language, 'fallback'));
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function languageProvider(): array
    {
        return [
            'english slug'         => ['en', 'en_GB'],
            'spanish slug'         => ['es', 'es_ES'],
            'catalan slug'         => ['ca', 'ca'],
            'german slug'          => ['de', 'de_DE'],
            'french slug'          => ['fr', 'fr_FR'],
            'italian slug'         => ['it', 'it_IT'],
            'dutch slug'           => ['nl', 'nl_NL'],
            'locale with region'   => ['es_ES', 'es_ES'],
            'hyphenated region'    => ['en-US', 'en_GB'],
            'upper case'           => [' EN ', 'en_GB'],
            'unknown language'     => ['xx', 'fallback'],
            'empty language'       => ['', 'fallback'],
        ];
    }

    public function testRunReturnsTheCallbackResult(): void
    {
        self::assertSame('done', LocaleSwitcher::run('en', static fn (): string => 'done'));
    }

    public function testRunPropagatesExceptions(): void
    {
        $this->expectException(\RuntimeException::class);

        LocaleSwitcher::run('en', static function (): void {
            throw new \RuntimeException('boom');
        });
    }
}
