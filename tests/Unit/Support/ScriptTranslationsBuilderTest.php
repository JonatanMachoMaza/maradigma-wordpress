<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\ScriptTranslationsBuilder;
use PHPUnit\Framework\TestCase;

final class ScriptTranslationsBuilderTest extends TestCase
{
    public function testItExtractsOnlyPluginDomainCalls(): void
    {
        $source = <<<'JS'
            var __ = wp.i18n.__;
            el(Button, {}, __('Clear boat', 'maradigma'));
            label: __("Boat's \"name\"", 'maradigma'),
            help: __( 'Type at least 2 characters to search.' , "maradigma" ),
            other: __('Core string', 'default'),
            dup: __('Clear boat', 'maradigma'),
            method: obj.__('Not ours', 'maradigma'),
            JS;

        self::assertSame(
            ['Clear boat', 'Boat\'s "name"', 'Type at least 2 characters to search.'],
            ScriptTranslationsBuilder::extractMsgids($source)
        );
    }

    public function testItBuildsJedJsonWithOnlyTranslatedScriptStrings(): void
    {
        $entries = [
            ['msgid' => '', 'msgstr' => "Content-Type: text/plain; charset=UTF-8\nPlural-Forms: nplurals=2; plural=(n != 1);\n"],
            ['msgid' => 'Clear boat', 'msgstr' => 'Quitar barco'],
            ['msgid' => 'Maradigma boat', 'msgstr' => ''],
            ['msgid' => 'PHP only', 'msgstr' => 'Solo PHP'],
        ];

        $json = ScriptTranslationsBuilder::buildJson(
            $entries,
            ['Maradigma boat', 'Clear boat', 'Missing'],
            'es_ES',
            'assets/js/editor/maradigma-gutenberg-blocks.js'
        );

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        $messages = $decoded['locale_data']['messages'];
        self::assertSame('es_ES', $messages['']['lang']);
        self::assertSame('nplurals=2; plural=(n != 1);', $messages['']['plural-forms']);
        self::assertSame(['Quitar barco'], $messages['Clear boat']);
        self::assertArrayNotHasKey('Maradigma boat', $messages);
        self::assertArrayNotHasKey('PHP only', $messages);
        self::assertArrayNotHasKey('Missing', $messages);
    }
}
