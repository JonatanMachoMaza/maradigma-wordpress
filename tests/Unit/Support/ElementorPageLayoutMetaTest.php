<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\ElementorPageLayoutMeta;
use PHPUnit\Framework\TestCase;

final class ElementorPageLayoutMetaTest extends TestCase
{
    public function testItInheritsTheElementorFullWidthLayout(): void
    {
        self::assertSame(
            ['_wp_page_template' => 'elementor_header_footer'],
            ElementorPageLayoutMeta::inheritable([
                '_wp_page_template'        => 'elementor_header_footer',
                '_elementor_page_settings' => '',
            ])
        );
    }

    public function testItInheritsPageSettingsAlongsideTheLayout(): void
    {
        $settings = ['padding' => ['top' => '0'], 'custom_css' => '.a { content: "\\"" }'];

        self::assertSame(
            ['_wp_page_template' => 'elementor_canvas', '_elementor_page_settings' => $settings],
            ElementorPageLayoutMeta::inheritable([
                '_wp_page_template'        => 'elementor_canvas',
                '_elementor_page_settings' => $settings,
            ])
        );
    }

    public function testDefaultAndEmptyValuesCarryNoLayout(): void
    {
        self::assertSame([], ElementorPageLayoutMeta::inheritable([]));
        self::assertSame([], ElementorPageLayoutMeta::inheritable([
            '_wp_page_template'        => 'default',
            '_elementor_page_settings' => [],
        ]));
        self::assertSame([], ElementorPageLayoutMeta::inheritable([
            '_wp_page_template'        => '  ',
            '_elementor_page_settings' => null,
        ]));
    }

    public function testNonStringTemplateAndNonArraySettingsAreIgnored(): void
    {
        self::assertSame([], ElementorPageLayoutMeta::inheritable([
            '_wp_page_template'        => ['elementor_header_footer'],
            '_elementor_page_settings' => 'a:0:{}',
        ]));
    }

    public function testTemplateNameIsTrimmed(): void
    {
        self::assertSame(
            ['_wp_page_template' => 'elementor_header_footer'],
            ElementorPageLayoutMeta::inheritable(['_wp_page_template' => " elementor_header_footer\n"])
        );
    }

    public function testCopyToTheSamePostOrAnInvalidPostDoesNothing(): void
    {
        // None of these reach a WordPress function, which would be undefined here.
        ElementorPageLayoutMeta::copy(5, 5);
        ElementorPageLayoutMeta::copy(0, 5);
        ElementorPageLayoutMeta::copy(5, 0);

        $this->addToAssertionCount(1);
    }
}
