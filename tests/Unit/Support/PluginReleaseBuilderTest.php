<?php

declare(strict_types=1);

namespace Maradigma\Tests\Unit\Support;

use Maradigma\Support\PluginReleaseBuilder;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class PluginReleaseBuilderTest extends TestCase
{
    private string $pluginRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pluginRoot = sys_get_temp_dir() . '/maradigma-release-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->pluginRoot, 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->pluginRoot);
        parent::tearDown();
    }

    public function testRuntimeVendorAssetsArePackagedButComposerVendorIsExcluded(): void
    {
        $mainFile = $this->pluginRoot . '/maradigma.php';
        file_put_contents($mainFile, "<?php\n/**\n * Version: 1.2.3\n */\n");

        $this->writeFixture('assets/dist/css/vendor/flatpickr.min.css', 'flatpickr-css');
        $this->writeFixture('assets/dist/js/vendor/flatpickr.min.js', 'flatpickr-js');
        $this->writeFixture('vendors/select2/select2.full.min.js', 'select2-runtime');
        $this->writeFixture('vendors/select2/i18n/eo.js', 'select2-unused-i18n');
        $this->writeFixture('languages/maradigma-es_ES.mo', 'compiled-runtime-catalog');
        $this->writeFixture('languages/maradigma-es_ES.po', 'translation-source');
        $this->writeFixture('languages/maradigma.pot', 'translation-template');
        $this->writeFixture('.agents/skills/maradigma-release/SKILL.md', 'development-agent-skill');
        $this->writeFixture('vendor/autoload.php', '<?php');

        $builder = new PluginReleaseBuilder($this->pluginRoot, $mainFile, 'maradigma');
        $result = $builder->build();

        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['zip_path']));

        try {
            self::assertNotFalse($zip->locateName('maradigma/assets/dist/css/vendor/flatpickr.min.css'));
            self::assertNotFalse($zip->locateName('maradigma/assets/dist/js/vendor/flatpickr.min.js'));
            self::assertNotFalse($zip->locateName('maradigma/vendors/select2/select2.full.min.js'));
            self::assertNotFalse($zip->locateName('maradigma/languages/maradigma-es_ES.mo'));
            self::assertFalse($zip->locateName('maradigma/vendors/select2/i18n/eo.js'));
            self::assertFalse($zip->locateName('maradigma/languages/maradigma-es_ES.po'));
            self::assertFalse($zip->locateName('maradigma/languages/maradigma.pot'));
            self::assertFalse($zip->locateName('maradigma/.agents/skills/maradigma-release/SKILL.md'));
            self::assertFalse($zip->locateName('maradigma/vendor/autoload.php'));
        } finally {
            $zip->close();
        }
    }

    private function writeFixture(string $relativePath, string $contents): void
    {
        $path = $this->pluginRoot . '/' . $relativePath;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }

        self::assertNotFalse(file_put_contents($path, $contents));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
