<?php

declare(strict_types=1);

namespace {
    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir()
        {
            if (!empty($GLOBALS['maradigma_test_upload_error'])) {
                return false;
            }

            return [
                'basedir' => (string) ($GLOBALS['maradigma_test_upload_dir'] ?? ''),
                'error'   => false,
            ];
        }
    }

    if (!function_exists('trailingslashit')) {
        function trailingslashit(string $value): string
        {
            return rtrim($value, '/\\') . '/';
        }
    }

    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p(string $path): bool
        {
            return is_dir($path) || mkdir($path, 0777, true);
        }
    }

    if (!function_exists('wp_delete_file')) {
        function wp_delete_file(string $path): void
        {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    if (!function_exists('wp_json_encode')) {
        /** @param mixed $value */
        function wp_json_encode($value, int $flags = 0): string|false
        {
            return json_encode($value, $flags);
        }
    }
}

namespace Maradigma\Tests\Unit\Support {
    use Maradigma\Support\Debugger;
    use PHPUnit\Framework\TestCase;

    final class DebuggerTest extends TestCase
    {
        private string $uploadDir;

        protected function setUp(): void
        {
            parent::setUp();

            if (!defined('MARADIGMA_PLUGIN_DEBUG')) {
                define('MARADIGMA_PLUGIN_DEBUG', false);
            }

            $this->uploadDir = sys_get_temp_dir() . '/maradigma-debugger-' . bin2hex(random_bytes(6));
            $GLOBALS['maradigma_test_upload_dir'] = $this->uploadDir;
        }

        protected function tearDown(): void
        {
            $this->removeDirectory($this->uploadDir);
            unset($GLOBALS['maradigma_test_upload_dir']);

            parent::tearDown();
        }

        public function testOperationalErrorsAreWrittenWhenVerboseDebuggingIsDisabled(): void
        {
            Debugger::error('boat-sync', 'Synchronization failed', ['phase' => 'layout_template']);

            $contents = Debugger::read('boat-sync');
            self::assertNotSame('', $contents);

            $entry = json_decode(trim($contents), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('error', $entry['level'] ?? null);
            self::assertSame('Synchronization failed', $entry['message'] ?? null);
            self::assertSame('layout_template', $entry['context']['phase'] ?? null);
            self::assertFileExists($this->uploadDir . '/maradigma/logs/index.html');
            self::assertSame('', (string) file_get_contents($this->uploadDir . '/maradigma/logs/index.html'));
            self::assertFileDoesNotExist($this->uploadDir . '/maradigma/logs/index.php');
        }

        public function testLegacyPhpIndexIsRemoved(): void
        {
            $logsDirectory = $this->uploadDir . '/maradigma/logs';
            self::assertTrue(mkdir($logsDirectory, 0777, true));
            self::assertNotFalse(file_put_contents(
                $logsDirectory . '/index.php',
                'legacy executable content'
            ));

            Debugger::error('boat-sync', 'Synchronization failed');

            self::assertFileDoesNotExist($logsDirectory . '/index.php');
            self::assertFileExists($logsDirectory . '/index.html');
        }

        public function testRoutineLogsStayDisabledInProduction(): void
        {
            Debugger::log('routine', 'Routine diagnostic');

            self::assertSame('', Debugger::read('routine'));
            self::assertFalse(Debugger::exists('routine'));
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
}
