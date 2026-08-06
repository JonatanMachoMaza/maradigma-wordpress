<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\PoToMoCompiler;
use PHPUnit\Framework\TestCase;

final class PoToMoCompilerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir() . '/maradigma-mo-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->temporaryDirectory, 0777, true));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . '/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->temporaryDirectory);
        parent::tearDown();
    }

    public function testHashOffsetStartsAfterBothStringIndexTables(): void
    {
        $poPath = $this->temporaryDirectory . '/catalog.po';
        $moPath = $this->temporaryDirectory . '/catalog.mo';

        self::assertNotFalse(file_put_contents(
            $poPath,
            "msgid \"\"\nmsgstr \"Content-Type: text/plain; charset=UTF-8\\n\"\n\n"
            . "msgid \"Clear cache\"\nmsgstr \"Limpiar caché\"\n"
        ));

        PoToMoCompiler::compile($poPath, $moPath);

        $binary = file_get_contents($moPath);
        self::assertIsString($binary);
        self::assertGreaterThanOrEqual(28, strlen($binary));

        $header = unpack(
            'Vmagic/Vrevision/Vtotal/Voriginals_offset/Vtranslations_offset/Vhash_size/Vhash_offset',
            substr($binary, 0, 28)
        );

        self::assertIsArray($header);
        self::assertSame(0x950412de, $header['magic']);
        self::assertSame(0, $header['hash_size']);
        self::assertSame(
            $header['translations_offset'] + ($header['total'] * 8),
            $header['hash_offset']
        );
    }
}
