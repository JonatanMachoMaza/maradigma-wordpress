<?php

declare(strict_types=1);

namespace {
    /**
     * Returns a test option value for collaborators that call the global API.
     *
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        return $GLOBALS['boatPermalinksTestOptions'][$option] ?? $default;
    }

    /** Reports that no multilingual WordPress filter is active in unit tests. */
    function has_filter(string $hookName): bool
    {
        return false;
    }
}

namespace Maradigma {
    if (!defined('ABSPATH')) {
        define('ABSPATH', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR);
    }

    /** @var array<string,mixed> */
    $boatPermalinksTestOptions = [];

    $boatPermalinksTestFlushes = 0;

    /** @var list<array{regex:string,query:string,position:string}> */
    $boatPermalinksTestRules = [];

    /** @var array<string,bool> */
    $boatPermalinksTestPostTypes = [];

    /**
     * Returns a test option value.
     *
     * @param mixed $default Default value.
     *
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        global $boatPermalinksTestOptions;

        return $boatPermalinksTestOptions[$option] ?? $default;
    }

    /** Records a rewrite flush without invoking WordPress. */
    function flush_rewrite_rules(bool $hard = true): void
    {
        global $boatPermalinksTestFlushes;

        ++$boatPermalinksTestFlushes;
    }

    /** Returns whether a test post type has been registered. */
    function post_type_exists(string $postType): bool
    {
        global $boatPermalinksTestPostTypes;

        return $boatPermalinksTestPostTypes[$postType] ?? false;
    }

    /** Records a rewrite rule without invoking WordPress. */
    function add_rewrite_rule(string $regex, string $query, string $position = 'bottom'): void
    {
        global $boatPermalinksTestRules;

        $boatPermalinksTestRules[] = [
            'regex' => $regex,
            'query' => $query,
            'position' => $position,
        ];
    }

    /**
     * Stores a test option value.
     *
     * @param mixed $value Option value.
     */
    function update_option(string $option, $value, bool $autoload = true): bool
    {
        global $boatPermalinksTestOptions;

        $boatPermalinksTestOptions[$option] = $value;

        return true;
    }
}

namespace Maradigma\Tests\Unit {
    use Maradigma\BoatPermalinks;
    use Maradigma\BoatPostType;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;

    final class BoatPermalinksTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();

            $GLOBALS['boatPermalinksTestOptions'] = [];
            $GLOBALS['boatPermalinksTestFlushes'] = 0;
            $GLOBALS['boatPermalinksTestRules'] = [];
            $GLOBALS['boatPermalinksTestPostTypes'] = [];

            $scheduled = new ReflectionProperty(BoatPermalinks::class, 'rewriteRulesFlushScheduled');
            $scheduled->setValue(null, false);
        }

        public function testRewriteSchemaIsFlushedAndPersistedOnce(): void
        {
            BoatPermalinks::maybeFlushRewriteRules();

            self::assertSame(1, $GLOBALS['boatPermalinksTestFlushes']);
            self::assertSame(
                '2',
                $GLOBALS['boatPermalinksTestOptions']['maradigma_boat_rewrite_schema'] ?? null
            );

            BoatPermalinks::maybeFlushRewriteRules();

            self::assertSame(1, $GLOBALS['boatPermalinksTestFlushes']);
        }

        public function testScheduledRewriteRefreshWaitsForNextInitialization(): void
        {
            $GLOBALS['boatPermalinksTestOptions']['maradigma_boat_rewrite_schema'] = '2';

            BoatPermalinks::scheduleRewriteRulesFlush();

            self::assertSame(
                '',
                $GLOBALS['boatPermalinksTestOptions']['maradigma_boat_rewrite_schema'] ?? null
            );
            self::assertSame(0, $GLOBALS['boatPermalinksTestFlushes']);

            BoatPermalinks::maybeFlushRewriteRules();

            self::assertSame(0, $GLOBALS['boatPermalinksTestFlushes']);

            $scheduled = new ReflectionProperty(BoatPermalinks::class, 'rewriteRulesFlushScheduled');
            $scheduled->setValue(null, false);

            BoatPermalinks::maybeFlushRewriteRules();

            self::assertSame(1, $GLOBALS['boatPermalinksTestFlushes']);
            self::assertSame(
                '2',
                $GLOBALS['boatPermalinksTestOptions']['maradigma_boat_rewrite_schema'] ?? null
            );
        }

        public function testRegistersOnlyCompleteDynamicAndFallbackBoatRoutes(): void
        {
            $GLOBALS['boatPermalinksTestOptions']['maradigma_settings'] = [
                'enable_boat_pages_sync' => true,
                'default_language' => 'es',
                'boats_base_slug' => 'alquiler-de-barcos/{{destination}}/{{boat_type}}',
            ];
            $GLOBALS['boatPermalinksTestPostTypes'][BoatPostType::POST_TYPE] = true;

            BoatPermalinks::addRewriteRules();

            self::assertSame(
                [
                    [
                        'regex' => '^es/alquiler\\-de\\-barcos/([^/]+)/([^/]+)/([^/]+)/?$',
                        'query' => 'index.php?post_type=maradigma_boat&name=$matches[3]',
                        'position' => 'top',
                    ],
                    [
                        'regex' => '^es/boats/([^/]+)/?$',
                        'query' => 'index.php?post_type=maradigma_boat&name=$matches[1]',
                        'position' => 'top',
                    ],
                ],
                $GLOBALS['boatPermalinksTestRules']
            );

            foreach ($GLOBALS['boatPermalinksTestRules'] as $rule) {
                self::assertSame(0, preg_match('#' . $rule['regex'] . '#', 'es/alquiler-de-barcos/ibiza'));
                self::assertSame(0, preg_match('#' . $rule['regex'] . '#', 'es/alquiler-de-barcos/ibiza/lancha'));
            }
        }

        public function testDoesNotRegisterPublicRoutesWhenBoatPageSyncIsDisabled(): void
        {
            $GLOBALS['boatPermalinksTestOptions']['maradigma_settings'] = [
                'enable_boat_pages_sync' => false,
                'default_language' => 'es',
                'boats_base_slug' => 'alquiler-de-barcos/{{destination}}/{{boat_type}}',
            ];
            $GLOBALS['boatPermalinksTestPostTypes'][BoatPostType::POST_TYPE] = true;

            BoatPermalinks::addRewriteRules();

            self::assertSame([], $GLOBALS['boatPermalinksTestRules']);
        }
    }
}
