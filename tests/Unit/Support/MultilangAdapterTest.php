<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\MultilangAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MultilangAdapterTest extends TestCase
{
    /**
     * @param mixed        $input
     * @param list<string> $expected
     */
    #[DataProvider('wpmlLanguageRowsProvider')]
    public function testItNormalizesWpmlLanguageRows($input, array $expected): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
        }

        $method = new ReflectionMethod(MultilangAdapter::class, 'normalizeWpmlLanguages');

        self::assertSame($expected, $method->invoke(null, $input));
    }

    /**
     * @return iterable<string,array{0:mixed,1:list<string>}>
     */
    public static function wpmlLanguageRowsProvider(): iterable
    {
        yield 'official active languages shape' => [
            [
                'de' => ['language_code' => 'DE'],
                'es' => ['language_code' => 'es'],
                'en' => ['language_code' => 'EN'],
            ],
            ['de', 'es', 'en'],
        ];

        yield 'sitepress legacy code shape' => [
            [
                (object) ['code' => 'ca'],
                (object) ['language_code' => 'FR'],
            ],
            ['ca', 'fr'],
        ];

        yield 'associative keys and duplicates' => [
            [
                'de' => [],
                ['language_code' => 'DE'],
                'ES',
            ],
            ['de', 'es'],
        ];

        yield 'invalid response' => [null, []];
    }

    public function testItUsesGettextTranslationForAnUnchangedEditableDefault(): void
    {
        self::assertSame(
            'Equipamiento',
            MultilangAdapter::translateEditableDefault(
                'Equipments',
                'Equipments',
                'Equipamiento',
                'Maradigma Elementor Widgets',
                'boat_equipments_shortcode_title'
            )
        );
    }

    public function testItPreservesAnEmptyEditableValue(): void
    {
        self::assertSame(
            '',
            MultilangAdapter::translateEditableDefault(
                '',
                'Equipments',
                'Equipamiento',
                'Maradigma Elementor Widgets',
                'boat_equipments_shortcode_title'
            )
        );
    }
}
