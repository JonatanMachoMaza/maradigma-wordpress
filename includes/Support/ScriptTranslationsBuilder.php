<?php
declare(strict_types=1);

namespace Maradigma\Support;

use RuntimeException;

/**
 * Builds the JSON catalogs WordPress reads for wp.i18n in our editor scripts.
 *
 * WordPress never reads .mo files for JavaScript: wp_set_script_translations()
 * loads languages/<domain>-<locale>-<handle>.json instead. Each JSON holds only
 * the msgids the script actually uses, taken from the same .po catalogs.
 */
final class ScriptTranslationsBuilder
{
    public const TEXT_DOMAIN = 'maradigma';

    /**
     * Script handles registered with wp_set_script_translations(), mapped to
     * their source file relative to the plugin root.
     *
     * @var array<string,string>
     */
    public const SCRIPTS = [
        'maradigma-gutenberg-blocks' => 'assets/js/editor/maradigma-gutenberg-blocks.js',
    ];

    private const DEFAULT_PLURAL_FORMS = 'nplurals=2; plural=(n != 1);';

    /**
     * Returns the msgids of every __('...', 'maradigma') call in the source.
     *
     * @return list<string>
     */
    public static function extractMsgids(string $jsSource): array
    {
        $literal = '(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")';
        $pattern = '/(?<![\w.$])__\(\s*' . $literal . '\s*,\s*[\'"]' . self::TEXT_DOMAIN . '[\'"]\s*\)/';

        if (preg_match_all($pattern, $jsSource, $matches) === false) {
            throw new RuntimeException('Failed to scan the script for translatable strings.');
        }

        $msgids = [];
        foreach ($matches[1] as $quoted) {
            $msgid = stripcslashes(substr($quoted, 1, -1));
            if ($msgid !== '') {
                $msgids[$msgid] = true;
            }
        }

        return array_keys($msgids);
    }

    /**
     * Builds the Jed 1.x JSON WordPress expects, keeping only translated msgids.
     *
     * @param array<int,array{msgid:string,msgstr:string}> $poEntries
     * @param list<string> $msgids
     */
    public static function buildJson(array $poEntries, array $msgids, string $locale, string $source): string
    {
        $translations = [];
        $pluralForms = self::DEFAULT_PLURAL_FORMS;

        foreach ($poEntries as $entry) {
            if ($entry['msgid'] === '') {
                if (preg_match('/^Plural-Forms:\s*(.+?)\s*$/mi', $entry['msgstr'], $header) === 1) {
                    $pluralForms = $header[1];
                }
                continue;
            }

            if ($entry['msgstr'] !== '') {
                $translations[$entry['msgid']] = $entry['msgstr'];
            }
        }

        $messages = [];
        foreach ($msgids as $msgid) {
            if (isset($translations[$msgid])) {
                $messages[$msgid] = [$translations[$msgid]];
            }
        }
        ksort($messages, SORT_STRING);

        $json = json_encode(
            [
                'source'      => $source,
                'domain'      => 'messages',
                'locale_data' => [
                    'messages' => ['' => [
                        'domain'       => 'messages',
                        'lang'         => $locale,
                        'plural-forms' => $pluralForms,
                    ]] + $messages,
                ],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException('Failed to encode script translations for ' . $locale . '.');
        }

        return $json;
    }

    /**
     * Writes languages/maradigma-<locale>-<handle>.json for every script and
     * every <domain>-<locale>.po in the languages directory.
     *
     * @return list<string> Written file paths.
     */
    public static function writeAll(string $pluginRoot, string $languagesDir): array
    {
        $poFiles = glob(rtrim($languagesDir, '/\\') . DIRECTORY_SEPARATOR . self::TEXT_DOMAIN . '-*.po');
        if ($poFiles === false) {
            throw new RuntimeException('Cannot list .po files in: ' . $languagesDir);
        }

        $written = [];

        foreach (self::SCRIPTS as $handle => $relativePath) {
            $scriptPath = rtrim($pluginRoot, '/\\') . DIRECTORY_SEPARATOR . $relativePath;
            $source = file_get_contents($scriptPath);
            if ($source === false) {
                throw new RuntimeException('Script not readable: ' . $scriptPath);
            }

            $msgids = self::extractMsgids($source);

            foreach ($poFiles as $poPath) {
                $locale = substr(basename($poPath, '.po'), strlen(self::TEXT_DOMAIN) + 1);
                $json = self::buildJson(PoToMoCompiler::parsePoFile($poPath), $msgids, $locale, $relativePath);
                $jsonPath = dirname($poPath) . DIRECTORY_SEPARATOR . self::TEXT_DOMAIN . '-' . $locale . '-' . $handle . '.json';

                if (file_put_contents($jsonPath, $json . "\n") === false) {
                    throw new RuntimeException('Failed to write: ' . $jsonPath);
                }

                $written[] = $jsonPath;
            }
        }

        return $written;
    }
}
