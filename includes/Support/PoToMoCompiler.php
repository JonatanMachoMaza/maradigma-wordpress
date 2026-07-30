<?php
declare(strict_types=1);

namespace Maradigma\Support;

use RuntimeException;

/**
 * Minimal PO -> MO compiler (no external dependencies).
 *
 * Supports:
 * - msgid / msgstr (singular)
 * - multiline strings
 * - common escape sequences
 *
 * Limitations (by design):
 * - msgctxt is ignored (context not supported)
 * - plural forms are not supported (msgid_plural / msgstr[n])
 * - fuzzy entries are not filtered (you can add that if you need)
 *
 * This is enough for typical WordPress plugin translations like:
 *   msgid "Hello"
 *   msgstr "Hola"
 */
final class PoToMoCompiler
{
    /**
     * Compile a .po file into a .mo file.
     *
     * @throws RuntimeException
     */
    public static function compile(string $poFilePath, string $moFilePath): void
    {
        if (!is_file($poFilePath) || !is_readable($poFilePath)) {
            throw new RuntimeException('PO file not readable: ' . $poFilePath);
        }

        $entries = self::parsePoFile($poFilePath);

        // Build MO with all pairs msgid => msgstr (skip empty msgid unless you want headers)
        // IMPORTANT: Keep header entry (msgid "") because it contains charset/plural-forms, etc.
        // WordPress can work without it, but better to keep.
        $translations = [];
        foreach ($entries as $e) {
            $id = $e['msgid'];
            $tr = $e['msgstr'];

            // Keep header entry (msgid == "")
            if ($id === '' && $tr === '') {
                continue;
            }

            // If msgid not empty but msgstr empty, still include? Up to you.
            // We'll include it (WordPress will fallback to msgid).
            $translations[$id] = $tr;
        }

        self::writeMoFile($translations, $moFilePath);
    }

    /**
     * @return array<int,array{msgid:string,msgstr:string}>
     */
    private static function parsePoFile(string $poFilePath): array
    {
        $lines = file($poFilePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Failed to read PO file.');
        }

        $entries = [];

        $current = [
            'msgid'  => null,
            'msgstr' => null,
        ];

        $state = null; // 'msgid'|'msgstr'|null

        $flush = static function () use (&$entries, &$current): void {
            if ($current['msgid'] !== null && $current['msgstr'] !== null) {
                $entries[] = [
                    'msgid'  => $current['msgid'],
                    'msgstr' => $current['msgstr'],
                ];
            }
            $current = ['msgid' => null, 'msgstr' => null];
        };

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            // Skip empty lines -> end of entry
            if ($line === '') {
                // Only flush if we have something meaningful
                if ($current['msgid'] !== null || $current['msgstr'] !== null) {
                    // If msgstr is still null, make it empty to avoid losing entry
                    if ($current['msgid'] !== null && $current['msgstr'] === null) {
                        $current['msgstr'] = '';
                    }
                    if ($current['msgid'] === null && $current['msgstr'] !== null) {
                        $current['msgid'] = '';
                    }
                    $flush();
                }
                $state = null;
                continue;
            }

            // Comments
            if ($line !== '' && $line[0] === '#') {
                continue;
            }

            // Ignore unsupported plural/context forms safely
            if (str_starts_with($line, 'msgctxt') || str_starts_with($line, 'msgid_plural') || preg_match('/^msgstr\[\d+\]/', $line)) {
                // For now we skip plural/context handling.
                // If you need it, we can implement it.
                continue;
            }

            if (str_starts_with($line, 'msgid')) {
                $state = 'msgid';
                $current['msgid'] = self::extractQuotedString($line);
                // If msgstr already present, it's a new entry
                if ($current['msgstr'] !== null) {
                    // This is unusual but we handle it by flushing previous first
                    $flush();
                    $current['msgid'] = self::extractQuotedString($line);
                    $current['msgstr'] = null;
                }
                continue;
            }

            if (str_starts_with($line, 'msgstr')) {
                $state = 'msgstr';
                $current['msgstr'] = self::extractQuotedString($line);
                if ($current['msgid'] === null) {
                    $current['msgid'] = '';
                }
                continue;
            }

            // Multiline continuation: "...."
            if ($line !== '' && $line[0] === '"' && $state !== null) {
                $append = self::extractQuotedString($line);
                if ($state === 'msgid') {
                    $current['msgid'] = ($current['msgid'] ?? '') . $append;
                } elseif ($state === 'msgstr') {
                    $current['msgstr'] = ($current['msgstr'] ?? '') . $append;
                }
                continue;
            }

            // Unknown line -> ignore
        }

        // Flush last entry
        if ($current['msgid'] !== null || $current['msgstr'] !== null) {
            if ($current['msgid'] !== null && $current['msgstr'] === null) {
                $current['msgstr'] = '';
            }
            if ($current['msgid'] === null && $current['msgstr'] !== null) {
                $current['msgid'] = '';
            }
            $entries[] = [
                'msgid'  => (string)($current['msgid'] ?? ''),
                'msgstr' => (string)($current['msgstr'] ?? ''),
            ];
        }

        return $entries;
    }

    private static function extractQuotedString(string $line): string
    {
        // Find first " and last "
        $first = strpos($line, '"');
        if ($first === false) {
            return '';
        }
        $last = strrpos($line, '"');
        if ($last === false || $last <= $first) {
            return '';
        }
        $inside = substr($line, $first + 1, $last - $first - 1);

        return self::unescapePoString($inside);
    }

    private static function unescapePoString(string $s): string
    {
        // PO uses C-like escapes
        // We keep it small and safe
        $replacements = [
            '\\n'  => "\n",
            '\\r'  => "\r",
            '\\t'  => "\t",
            '\\"'  => '"',
            "\\'"  => "'",
            '\\\\' => '\\',
        ];
        return strtr($s, $replacements);
    }

    /**
     * @param array<string,string> $translations msgid => msgstr
     */
    private static function writeMoFile(array $translations, string $moFilePath): void
    {
        // Sort by msgid (required by many tools; safe for WP)
        ksort($translations, SORT_STRING);

        $ids  = [];
        $strs = [];

        foreach ($translations as $id => $str) {
            $ids[]  = (string)$id;
            $strs[] = (string)$str;
        }

        $count = count($ids);

        // MO format (little-endian):
        // header: 7 * 4 bytes
        // magic, revision, count, offsetOriginalTable, offsetTranslationTable, hashSize, hashOffset
        $headerSize = 7 * 4;
        $origTableOffset = $headerSize;
        $transTableOffset = $origTableOffset + ($count * 8);

        // String blocks start after both tables
        $stringBlockOffset = $transTableOffset + ($count * 8);

        $origTable = '';
        $transTable = '';

        $origStrings = '';
        $transStrings = '';

        $origOffsets = [];
        $transOffsets = [];

        // Build original strings block
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            $s = $ids[$i];
            // MO strings are NUL-terminated
            $bytes = $s;
            $len = strlen($bytes);
            $origOffsets[] = [$len, $stringBlockOffset + $cursor];
            $origStrings .= $bytes . "\0";
            $cursor += $len + 1;
        }

        // Build translation strings block (continues after originals)
        $transBlockOffset = $stringBlockOffset + strlen($origStrings);
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            $s = $strs[$i];
            $bytes = $s;
            $len = strlen($bytes);
            $transOffsets[] = [$len, $transBlockOffset + $cursor];
            $transStrings .= $bytes . "\0";
            $cursor += $len + 1;
        }

        // Build tables: each entry is (length, offset) as 2 uint32 little-endian
        for ($i = 0; $i < $count; $i++) {
            [$len, $off] = $origOffsets[$i];
            $origTable .= pack('V2', $len, $off);
        }

        for ($i = 0; $i < $count; $i++) {
            [$len, $off] = $transOffsets[$i];
            $transTable .= pack('V2', $len, $off);
        }

        // Header
        $magic = 0x950412de; // little-endian magic
        $revision = 0;
        $hashSize = 0;
        $hashOffset = 0;

        $header = pack(
            'V7',
            $magic,
            $revision,
            $count,
            $origTableOffset,
            $transTableOffset,
            $hashSize,
            $hashOffset
        );

        $bin = $header . $origTable . $transTable . $origStrings . $transStrings;

        $dir = dirname($moFilePath);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create directory: ' . $dir);
            }
        }

        $ok = file_put_contents($moFilePath, $bin);
        if ($ok === false) {
            throw new RuntimeException('Failed to write MO file: ' . $moFilePath);
        }
    }
}