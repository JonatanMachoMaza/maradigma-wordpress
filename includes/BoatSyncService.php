<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\MultilangAdapter;
use Maradigma\Integrations\Gutenberg\GutenbergIntegration;
use Maradigma\Integrations\WPBakery\WPBakeryIntegration;
use Maradigma\Support\Debugger;
use Maradigma\Support\SyncRuntimePolicy;

/**
 * Sync boats from Maradigma API into WP as CPT posts (batched, WP-Cron safe).
 *
 * Why batched:
 * - Avoids request timeouts by processing a limited number of boats per tick.
 *
 * Images:
 * - BoatImagesSyncService is the ONLY component that downloads/caches images.
 * - BoatSyncService NEVER downloads images.
 * - Here we only attach cached attachments (by boat_id) to the CPT post meta:
 *   - _maradigma_gallery_attachment_ids
 *   - featured image (thumbnail) set ONCE (cover first)
 */
final class BoatSyncService
{
    /** Prevent Elementor save hooks from recursively creating the master template. */
    private static bool $ensuringElementorMasterTemplate = false;

    // ─────────────────────────────────────────────
    // Sync state (cron)
    // ─────────────────────────────────────────────
    private const OPTION_STATE = 'maradigma_boat_sync_state';
    private const CRON_HOOK    = 'maradigma_boat_sync_tick';
    private const LOCK_OPTION = 'maradigma_boat_sync_lock_v2';
    private const LOCK_TRANSIENT = 'maradigma_boat_sync_lock';
    private const DEBUG_CHANNEL = 'boat-sync';
    private const AJAX_STATUS_ACTION = 'maradigma_boat_sync_status';
    private const AJAX_PUMP_ACTION   = 'maradigma_boat_sync_pump';
    private const AJAX_STOP_ACTION   = 'maradigma_boat_sync_stop';

    // ─────────────────────────────────────────────
    // Boat post meta
    // ─────────────────────────────────────────────
    private const META_BOAT_ID = '_maradigma_boat_id';
    private const META_PAYLOAD = '_maradigma_boat_payload';
    private const META_PAYLOAD_UPDATED_AT = '_maradigma_boat_payload_updated_at';

    private const META_MANAGED = '_maradigma_managed';
    private const META_DISABLE_SYNC = '_maradigma_disable_sync';

    // Elementor seeding tracking
    private const META_ELEMENTOR_SEEDED        = '_maradigma_elementor_seeded';
    private const META_ELEMENTOR_DATA_HASH     = '_maradigma_elementor_data_hash';
    private const META_ELEMENTOR_TEMPLATE_HASH = '_maradigma_elementor_template_hash';

    // Layout builder / Gutenberg seeding tracking
    private const META_LAYOUT_BUILDER              = '_maradigma_layout_builder';
    private const META_GUTENBERG_SEEDED            = '_maradigma_gutenberg_seeded';
    private const META_GUTENBERG_CONTENT_HASH      = '_maradigma_gutenberg_content_hash';
    private const META_GUTENBERG_TEMPLATE_HASH     = '_maradigma_gutenberg_template_hash';
    private const META_GUTENBERG_CUSTOM_LAYOUT     = '_maradigma_gutenberg_custom_layout';

    // Images (stored as attachment IDs)
    private const META_GALLERY_ATTACHMENT_IDS  = '_maradigma_gallery_attachment_ids';
    private const META_FEATURED_ATTACHMENT_ID  = '_maradigma_featured_attachment_id';

    // Batch tuning
    private const DEFAULT_BATCH_BOATS = 10; // boats per tick (tune to your server)
    private const MAX_BATCH_BOATS     = 50;

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'tick']);
        add_action('wp_ajax_' . self::AJAX_STATUS_ACTION, [self::class, 'handleAdminStatus']);
        add_action('wp_ajax_' . self::AJAX_PUMP_ACTION, [self::class, 'handleAdminPump']);
        add_action('wp_ajax_' . self::AJAX_STOP_ACTION, [self::class, 'handleAdminStop']);
    }

    /**
     * Start (or restart) a batched sync.
     *
     * @param array<string,mixed> $options Same options as syncNow().
     */
    public static function start(array $options = []): void
    {
        $options = self::normalizeOptions($options);
        delete_option(self::LOCK_OPTION);
        delete_transient(self::LOCK_TRANSIENT);
        wp_clear_scheduled_hook(self::CRON_HOOK);

        $state = [
            'run_id'      => wp_generate_uuid4(),
            'status'      => 'running', // running|done|stopped|error
            'started_at'  => time(),
            'last_run_at' => 0,
            'current_phase' => 'scheduled',

            // progress
            'offset'      => 0,
            'limit'       => self::DEFAULT_BATCH_BOATS,
            'boats_total' => 0,
            'boats_done'  => 0,

            // stats
            'unique_boats'          => 0,
            'posts_created_total'   => 0,
            'posts_updated_total'   => 0,
            'per_lang'              => [],
            'languages_on_pages'    => 0,
            'language_codes'        => [],
            'language_checkpoint'   => [
                'boat_id'             => '',
                'completed_languages' => [],
            ],
            'api_boat_ids'          => [],
            'api_list_valid'        => false,
            'cleanup'               => self::makeInitialCleanupState($options),

            // config
            'options'     => $options,
            'message'     => '',
        ];

        update_option(self::OPTION_STATE, $state, false);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $scheduled = wp_schedule_single_event(time() + 2, self::CRON_HOOK);
            if ($scheduled === false || is_wp_error($scheduled)) {
                $state['message'] = is_wp_error($scheduled)
                    ? 'WP-Cron could not be scheduled: ' . $scheduled->get_error_message()
                    : 'WP-Cron could not be scheduled. Keep this page open so the admin pump can continue the sync.';
                update_option(self::OPTION_STATE, $state, false);
            }
        }
    }

    /**
     * Stop a running sync.
     */
    public static function stop(): void
    {
        $state = self::getState();
        $state['status']  = 'stopped';
        $state['message'] = 'Stopped by user';
        $state['current_phase'] = 'stopped';
        delete_option(self::LOCK_OPTION);
        delete_transient(self::LOCK_TRANSIENT);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Cron tick: process one batch and reschedule until done.
     */
    public static function tick(): void
    {
        $state = self::getState();
        $runId = (string)($state['run_id'] ?? '');

        if (($state['status'] ?? '') === 'running' && $runId === '') {
            $runId = wp_generate_uuid4();
            $state['run_id'] = $runId;
            $state['current_phase'] = 'migrated';
            $state['message'] = 'Migrated a synchronization started by an earlier plugin version.';
            update_option(self::OPTION_STATE, $state, false);
            self::debug('legacy_run_migrated', ['run_id' => $runId]);
        }

        self::registerFatalLogger('tick', $runId);

        if (($state['status'] ?? '') !== 'running') {
            self::debug('tick_skipped_not_running', ['status' => (string)($state['status'] ?? '')]);
            return;
        }

        self::clearStaleLockIfNeeded($state);

        $lockToken = self::acquireLock($runId);
        if ($lockToken === '') {
            $state = self::getState();
            $state['message'] = self::getActiveLockMessage($state);
            update_option(self::OPTION_STATE, $state, false);
            self::debug('tick_skipped_locked', [
                'started_at'  => (int)($state['started_at'] ?? 0),
                'last_run_at' => (int)($state['last_run_at'] ?? 0),
                'message'     => (string)($state['message'] ?? ''),
            ]);
            return;
        }

        $batchStartedAt = microtime(true);
        $state = self::getState();
        if (!self::isCurrentRunActive($runId, $state)) {
            self::releaseLock($lockToken);
            self::debug('tick_aborted_superseded_before_start', ['run_id' => $runId]);
            return;
        }

        $state['last_run_at'] = time();
        $state['current_phase'] = 'initializing';
        $state['message'] = 'Processing synchronization batch.';
        update_option(self::OPTION_STATE, $state, false);
        if (!self::heartbeatLock($lockToken, $runId, 'initializing')) {
            self::releaseLock($lockToken);
            self::debug('tick_aborted_superseded_during_start', ['run_id' => $runId]);
            return;
        }

        self::debug('tick_started', [
            'run_id' => $runId,
            'offset' => (int)($state['offset'] ?? 0),
            'limit'  => (int)($state['limit'] ?? 0),
            'options' => is_array($state['options'] ?? null) ? (array)$state['options'] : [],
        ]);

        $options = is_array(($state['options'] ?? null)) ? (array)$state['options'] : self::normalizeOptions([]);

        $limit  = isset($state['limit']) ? (int)$state['limit'] : self::DEFAULT_BATCH_BOATS;
        $offset = isset($state['offset']) ? (int)$state['offset'] : 0;

        if ($limit < 1) $limit = self::DEFAULT_BATCH_BOATS;
        if ($limit > self::MAX_BATCH_BOATS) $limit = self::MAX_BATCH_BOATS;
        if ($offset < 0) $offset = 0;

        try {
            $layoutBuilder = (string)($options['layout_builder'] ?? self::getConfiguredLayoutBuilder());
            $layoutBuilder = in_array($layoutBuilder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $layoutBuilder : 'elementor';

            self::beginPhase($lockToken, $runId, 'layout_template', $batchStartedAt, [
                'layout_builder' => $layoutBuilder,
            ]);
            $elementorTemplateId = $layoutBuilder === 'elementor' ? self::ensureElementorMasterTemplate() : 0;

            if ($layoutBuilder === 'gutenberg') {
                self::ensureGutenbergMasterTemplate();
            } elseif ($layoutBuilder === 'wpbakery') {
                self::ensureWPBakeryMasterTemplate();
            }
            self::finishPhase('layout_template', $batchStartedAt, [
                'layout_builder' => $layoutBuilder,
                'elementor_template_id' => $elementorTemplateId,
            ]);

            self::beginPhase($lockToken, $runId, 'resolve_languages', $batchStartedAt);
            [$langs, $sourceLang] = self::resolveLanguages();
            $state['language_codes'] = $langs;
            $state['languages_on_pages'] = count($langs);
            foreach ($langs as $resolvedLanguage) {
                if (!isset($state['per_lang'][$resolvedLanguage]) || !is_array($state['per_lang'][$resolvedLanguage])) {
                    $state['per_lang'][$resolvedLanguage] = ['created' => 0, 'updated' => 0, 'total' => 0];
                }
            }
            update_option(self::OPTION_STATE, $state, false);
            self::finishPhase('resolve_languages', $batchStartedAt, [
                'languages' => $langs,
                'source_language' => $sourceLang,
            ]);

            $cache = new Cache();

            self::beginPhase($lockToken, $runId, 'boats_list_request', $batchStartedAt, [
                'offset' => $offset,
                'limit' => $limit,
            ]);
            $result = $cache->getBoatsList([
                'id_group'        => 'boats',
                'limit_services'  => $limit,
                'offset_services' => $offset,
            ]);
            self::finishPhase('boats_list_request', $batchStartedAt, [
                'success' => !empty($result['success']),
            ]);
            self::debug('boats_list_response', [
                'success' => !empty($result['success']),
                'data_keys' => is_array($result['data'] ?? null) ? array_keys((array)$result['data']) : [],
                'status' => (string)($result['status'] ?? ''),
                'message' => (string)($result['message'] ?? ''),
            ]);

            $boats = [];
            $total = 0;

            if (empty($result['success']) || !is_array($result['data'] ?? null)) {
                throw new \RuntimeException('Boat list request failed. Obsolete-page cleanup was skipped for safety.');
            }

            $boats = is_array($result['data']['search_result'] ?? null) ? (array)$result['data']['search_result'] : [];
            $total = isset($result['data']['total_results']) ? (int)$result['data']['total_results'] : 0;

            if ((int)($state['boats_total'] ?? 0) <= 0 && $total > 0) {
                $state['boats_total'] = $total;
            }

            if (empty($boats)) {
                if ($offset === 0) {
                    $state['api_list_valid'] = false;
                    $state['message'] = 'Maradigma returned an empty boat list. Obsolete-page cleanup was skipped for safety.';
                }

                self::beginPhase($lockToken, $runId, 'cleanup', $batchStartedAt);
                $state = self::maybeCleanupObsoleteBoatPosts($state);
                self::finishPhase('cleanup', $batchStartedAt);
                $state['status']  = 'done';
                $state['current_phase'] = 'done';
                if ((string)($state['message'] ?? '') === '') {
                    $state['message'] = 'No more boats to process';
                }
                update_option(self::OPTION_STATE, $state, false);

                if (\class_exists(\Maradigma\SettingsPage::class) && \method_exists(\Maradigma\SettingsPage::class, 'storeBoatSyncFinished')) {
                    \Maradigma\SettingsPage::storeBoatSyncFinished($state);
                }

                return;
            }

            $applyYoast = static function (int $pid, array $data, string $lang) use ($options): void {
                $mode = (string)($options['yoast_mode'] ?? 'fix_multilang');
                if ($mode === 'skip') return;

                SeoIntegration::applyYoastMetaToPost($pid, $data, [
                    'mode' => $mode,
                    'lang' => $lang,
                ]);
            };

            $maybeApplyLayout = static function (int $pid) use ($options, $layoutBuilder, $elementorTemplateId): void {
                if ($layoutBuilder === 'gutenberg') {
                    $mode = (string)($options['gutenberg_mode'] ?? 'seed_missing');
                    if ($mode === 'skip') return;

                    self::seedGutenbergToBoatPost($pid, $mode);
                    return;
                }

                if ($layoutBuilder === 'wpbakery') {
                    $mode = (string)($options['wpbakery_mode'] ?? 'seed_missing');
                    if ($mode === 'skip') return;

                    self::seedWPBakeryToBoatPost($pid, $mode);
                    return;
                }

                $mode = (string)($options['elementor_mode'] ?? 'seed_missing');
                if ($mode === 'skip') return;

                if ($mode === 'seed_missing') {
                    $current = (string)get_post_meta($pid, '_elementor_data', true);
                    if ($current === '') {
                        self::seedElementorToBoatPost($pid, $elementorTemplateId);
                    }
                    return;
                }

                if (self::shouldOverwriteElementor($pid, $elementorTemplateId)) {
                    self::seedElementorToBoatPost($pid, $elementorTemplateId);
                }
            };

            $consumedInBatch = 0;
            $processedInBatch = 0;
            $yieldedForTimeBudget = false;

            foreach ($boats as $boat) {
                if (self::hasBatchTimeBudgetExpired($batchStartedAt)) {
                    $yieldedForTimeBudget = true;
                    self::debug('tick_time_budget_reached', [
                        'run_id' => $runId,
                        'elapsed_ms' => SyncRuntimePolicy::elapsedMilliseconds($batchStartedAt, microtime(true)),
                        'consumed_in_batch' => $consumedInBatch,
                        'processed_in_batch' => $processedInBatch,
                    ]);
                    break;
                }

                ++$consumedInBatch;
                if (!is_array($boat)) continue;

                $boatId = (string)($boat['id'] ?? $boat['id_gi'] ?? $boat['id_group_item'] ?? '');
                $boatId = trim($boatId);
                if ($boatId === '') continue;

                $state['api_boat_ids'][] = $boatId;
                $state['api_boat_ids'] = array_values(array_unique(array_map('strval', (array)($state['api_boat_ids'] ?? []))));
                $state['api_list_valid'] = true;

                $checkpoint = is_array($state['language_checkpoint'] ?? null)
                    ? (array)$state['language_checkpoint']
                    : [];
                $checkpointBoatId = trim((string)($checkpoint['boat_id'] ?? ''));
                $completedLanguages = is_array($checkpoint['completed_languages'] ?? null)
                    ? array_values(array_unique(array_filter(array_map(
                        static fn($language): string => strtolower(trim((string)$language)),
                        (array)$checkpoint['completed_languages']
                    ))))
                    : [];

                if ($checkpointBoatId !== $boatId) {
                    $completedLanguages = [];
                    $state['unique_boats'] = (int)($state['unique_boats'] ?? 0) + 1;
                    $state['language_checkpoint'] = [
                        'boat_id'             => $boatId,
                        'completed_languages' => [],
                    ];
                }

                $langToPostId = [];

                foreach ($langs as $lang) {
                    if (self::hasBatchTimeBudgetExpired($batchStartedAt)) {
                        $yieldedForTimeBudget = true;
                        --$consumedInBatch;
                        self::debug('tick_time_budget_reached_between_languages', [
                            'run_id' => $runId,
                            'boat_id' => $boatId,
                            'elapsed_ms' => SyncRuntimePolicy::elapsedMilliseconds($batchStartedAt, microtime(true)),
                        ]);
                        break;
                    }

                    $lang = strtolower(trim((string)$lang));
                    if ($lang === '') continue;

                    if (in_array($lang, $completedLanguages, true)) {
                        $completedPostId = self::findPostIdByBoatIdAndLang($boatId, $lang);
                        if ($completedPostId > 0) {
                            $langToPostId[$lang] = $completedPostId;
                            continue;
                        }

                        // The post disappeared after the checkpoint; recreate it.
                        $completedLanguages = array_values(array_diff($completedLanguages, [$lang]));
                    }

                    if (!isset($state['per_lang'][$lang]) || !is_array($state['per_lang'][$lang])) {
                        $state['per_lang'][$lang] = ['created' => 0, 'updated' => 0, 'total' => 0];
                    }

                    $apiLang = strtoupper($lang);

                    $boatFull = $boat;
                    self::beginPhase($lockToken, $runId, 'boat_detail', $batchStartedAt, [
                        'boat_id' => $boatId,
                        'language' => $apiLang,
                    ]);
                    try {
                        $detail = $cache->getBoatDetails(
                            $boatId,
                            $apiLang,
                            [
                                'expand'                 => ['service_images'],
                                'images'                 => 1,
                                'url_images_main_domain' => 1,
                                'only_load_cover_image'  => 0,
                            ],
                            ['images'],
                            true
                        );

                        if (is_array($detail) && ($detail['status'] ?? null) === 'success' && is_array($detail['data'] ?? null)) {
                            $boatFull = $detail['data'];
                            $boatFull['id'] = $boatFull['id'] ?? $boatId;
                        }
                    } catch (\Throwable $detailError) {
                        self::debug('boat_detail_fallback', [
                            'run_id' => $runId,
                            'boat_id' => $boatId,
                            'language' => $apiLang,
                            'exception' => $detailError,
                        ]);
                    }
                    self::finishPhase('boat_detail', $batchStartedAt, [
                        'boat_id' => $boatId,
                        'language' => $apiLang,
                    ]);

                    if (self::hasBatchTimeBudgetExpired($batchStartedAt)) {
                        $yieldedForTimeBudget = true;
                        --$consumedInBatch;
                        self::debug('tick_time_budget_reached_after_detail', [
                            'run_id' => $runId,
                            'boat_id' => $boatId,
                            'language' => $apiLang,
                            'elapsed_ms' => SyncRuntimePolicy::elapsedMilliseconds($batchStartedAt, microtime(true)),
                        ]);
                        break;
                    }

                    self::beginPhase($lockToken, $runId, 'boat_post_sync', $batchStartedAt, [
                        'boat_id' => $boatId,
                        'language' => $lang,
                    ]);

                    $name = (string)($boatFull['service_name'] ?? $boat['service_name'] ?? $boat['name'] ?? $boat['title'] ?? ('Boat ' . $boatId));
                    $name = trim($name);
                    if ($name === '') $name = 'Boat ' . $boatId;

                    $suggestedSlug = (string)($boatFull['slug'] ?? $boat['slug'] ?? $boatFull['service_slug'] ?? $boat['service_slug'] ?? '');
                    $suggestedSlug = trim($suggestedSlug);
                    if ($suggestedSlug === '') $suggestedSlug = $name;

                    $postSlug = sanitize_title($suggestedSlug);
                    $postSlug = wp_unique_post_slug($postSlug, 0, 'publish', BoatPostType::POST_TYPE, 0);

                    $payloadJson = wp_json_encode($boatFull, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    $postId = self::findPostIdByBoatIdAndLang($boatId, $lang);

                    if ($postId > 0) {
                        $disable = (bool)get_post_meta($postId, self::META_DISABLE_SYNC, true);

                        if (!$disable) {
                            $managed = (bool)get_post_meta($postId, self::META_MANAGED, true);

                            if ($managed && !empty($options['update_post_title_slug'])) {
                                $safeSlug = wp_unique_post_slug($postSlug, $postId, 'publish', BoatPostType::POST_TYPE, 0);

                                wp_update_post([
                                    'ID'         => $postId,
                                    'post_title' => $name,
                                    'post_name'  => $safeSlug,
                                ]);

                                $state['posts_updated_total'] = (int)($state['posts_updated_total'] ?? 0) + 1;
                                $state['per_lang'][$lang]['updated'] = (int)$state['per_lang'][$lang]['updated'] + 1;
                            }

                            if ($layoutBuilder === 'gutenberg') {
                                if (($options['gutenberg_mode'] ?? 'seed_missing') !== 'skip') {
                                    $maybeApplyLayout($postId);
                                }
                            } elseif ($layoutBuilder === 'wpbakery') {
                                if (($options['wpbakery_mode'] ?? 'seed_missing') !== 'skip') {
                                    $maybeApplyLayout($postId);
                                }
                            } elseif (!empty($options['elementor_mode']) && $options['elementor_mode'] !== 'skip') {
                                $maybeApplyLayout($postId);
                            }
                        }

                        if (!empty($options['update_payload']) && is_string($payloadJson)) {
                            update_post_meta($postId, self::META_PAYLOAD, $payloadJson);
                            update_post_meta($postId, self::META_PAYLOAD_UPDATED_AT, time());
                        }

                        if (!empty($options['sync_images'])) {
                            self::syncBoatImages($postId, $boatId); // ✅ attach only (WP cache)
                        }

                        MultilangAdapter::setPostLanguage($postId, $lang);
                        $applyYoast($postId, $boatFull, $lang);
                    } else {
                        $safeSlug = wp_unique_post_slug($postSlug, 0, 'publish', BoatPostType::POST_TYPE, 0);

                        $inserted = wp_insert_post([
                            'post_type'    => BoatPostType::POST_TYPE,
                            'post_status'  => 'publish',
                            'post_title'   => $name,
                            'post_name'    => $safeSlug,
                            'post_content' => '',
                        ], true);

                        if (!is_wp_error($inserted) && (int)$inserted > 0) {
                            $postId = (int)$inserted;

                            update_post_meta($postId, self::META_BOAT_ID, $boatId);
                            update_post_meta($postId, self::META_MANAGED, true);
                            update_post_meta($postId, self::META_DISABLE_SYNC, false);

                            if (!empty($options['update_payload']) && is_string($payloadJson)) {
                                update_post_meta($postId, self::META_PAYLOAD, $payloadJson);
                                update_post_meta($postId, self::META_PAYLOAD_UPDATED_AT, time());
                            }

                            if ($layoutBuilder === 'gutenberg') {
                                if (($options['gutenberg_mode'] ?? 'seed_missing') !== 'skip') {
                                    self::seedGutenbergToBoatPost($postId, (string) ($options['gutenberg_mode'] ?? 'seed_missing'));
                                }
                            } elseif ($layoutBuilder === 'wpbakery') {
                                if (($options['wpbakery_mode'] ?? 'seed_missing') !== 'skip') {
                                    self::seedWPBakeryToBoatPost($postId, (string) ($options['wpbakery_mode'] ?? 'seed_missing'));
                                }
                            } elseif (($options['elementor_mode'] ?? 'seed_missing') !== 'skip') {
                                self::seedElementorToBoatPost($postId, $elementorTemplateId);
                            }

                            if (!empty($options['sync_images'])) {
                                self::syncBoatImages($postId, $boatId); // ✅ attach only (WP cache)
                            }

                            MultilangAdapter::setPostLanguage($postId, $lang);
                            $applyYoast($postId, $boatFull, $lang);

                            $state['posts_created_total'] = (int)($state['posts_created_total'] ?? 0) + 1;
                            $state['per_lang'][$lang]['created'] = (int)$state['per_lang'][$lang]['created'] + 1;
                        }
                    }

                    if ($postId > 0) {
                        $langToPostId[$lang] = $postId;
                    } else {
                        throw new \RuntimeException(sprintf(
                            'Could not create or update boat %s in language %s.',
                            $boatId,
                            $lang
                        ));
                    }

                    self::finishPhase('boat_post_sync', $batchStartedAt, [
                        'boat_id' => $boatId,
                        'language' => $lang,
                        'post_id' => $postId,
                    ]);

                    $completedLanguages[] = $lang;
                    $completedLanguages = array_values(array_unique($completedLanguages));
                    $state['language_checkpoint'] = [
                        'boat_id'             => $boatId,
                        'completed_languages' => $completedLanguages,
                    ];
                    $state['last_run_at'] = time();
                    $state['current_phase'] = 'language_completed';
                    $state['message'] = sprintf('Processed boat %s in language %s.', $boatId, $lang);
                    update_option(self::OPTION_STATE, $state, false);

                    if (!self::heartbeatLock($lockToken, $runId, 'language_completed')) {
                        throw new \RuntimeException('Sync run was stopped or superseded.');
                    }
                }

                if ($yieldedForTimeBudget) {
                    break;
                }

                if (count($langToPostId) >= 2) {
                    MultilangAdapter::linkTranslations($langToPostId);
                }

                $state['language_checkpoint'] = [
                    'boat_id'             => '',
                    'completed_languages' => [],
                ];
                $state['boats_done'] = (int)($state['boats_done'] ?? 0) + 1;
                ++$processedInBatch;
                $state['last_run_at'] = time();
                $state['current_phase'] = 'boat_completed';
                $state['message'] = sprintf('Processed boat %s. Preparing the next item.', $boatId);
                update_option(self::OPTION_STATE, $state, false);

                if (!self::heartbeatLock($lockToken, $runId, 'boat_completed')) {
                    throw new \RuntimeException('Sync run was stopped or superseded.');
                }
            }

            foreach (($state['per_lang'] ?? []) as $l => $row) {
                $c = (int)($row['created'] ?? 0);
                $u = (int)($row['updated'] ?? 0);
                $state['per_lang'][$l]['total'] = $c + $u;
            }

            $state['offset'] = $offset + $consumedInBatch;
            $state['last_run_at'] = time();
            $state['current_phase'] = 'scheduled';
            $state['message'] = $yieldedForTimeBudget
                ? sprintf(
                    'Time budget reached after processing %d boats. Continuing at offset %d.',
                    $processedInBatch,
                    (int)$state['offset']
                )
                : sprintf('Processed %d boats (offset=%d)', $processedInBatch, (int)$state['offset']);

            if (!self::isCurrentRunActive($runId)) {
                throw new \RuntimeException('Sync run was stopped or superseded.');
            }

            if (\class_exists(\Maradigma\SettingsPage::class) && \method_exists(\Maradigma\SettingsPage::class, 'storeBoatSyncProgress')) {
                \Maradigma\SettingsPage::storeBoatSyncProgress($state);
            }

            update_option(self::OPTION_STATE, $state, false);
            wp_schedule_single_event(time() + 2, self::CRON_HOOK);
            self::debug('tick_finished', [
                'run_id' => $runId,
                'offset' => (int)($state['offset'] ?? 0),
                'boats_done' => (int)($state['boats_done'] ?? 0),
                'boats_total' => (int)($state['boats_total'] ?? 0),
                'consumed_in_batch' => $consumedInBatch,
                'processed_in_batch' => $processedInBatch,
                'yielded_for_time_budget' => $yieldedForTimeBudget,
                'elapsed_ms' => SyncRuntimePolicy::elapsedMilliseconds($batchStartedAt, microtime(true)),
            ]);
        } catch (\Throwable $e) {
            $currentState = self::getState();
            if (!self::isCurrentRunActive($runId, $currentState)) {
                self::debug('tick_aborted_superseded', [
                    'run_id' => $runId,
                    'exception' => $e,
                    'state' => self::summarizeState($currentState),
                ]);
            } else {
                $currentState['status'] = 'error';
                $currentState['current_phase'] = 'error';
                $currentState['message'] = $e->getMessage();
                self::error('tick_exception', [
                    'run_id' => $runId,
                    'exception' => $e,
                    'state' => self::summarizeState($currentState),
                ]);
                if (isset($currentState['cleanup']) && is_array($currentState['cleanup']) && (string)($currentState['cleanup']['action'] ?? 'none') !== 'none') {
                    $currentState['cleanup']['status'] = 'skipped';
                    $currentState['cleanup']['message'] = 'Cleanup skipped because the boat sync did not finish successfully.';
                }
                update_option(self::OPTION_STATE, $currentState, false);

                if (\class_exists(\Maradigma\SettingsPage::class) && \method_exists(\Maradigma\SettingsPage::class, 'storeBoatSyncFinished')) {
                    \Maradigma\SettingsPage::storeBoatSyncFinished($currentState);
                }
            }
        } finally {
            self::releaseLock($lockToken);
        }
    }

    /** @return array<string,mixed> */
    public static function getState(): array
    {
        $s = get_option(self::OPTION_STATE, []);
        return is_array($s) ? $s : [];
    }

    /**
     * Acquires lock.
     */
    private static function acquireLock(string $runId): string
    {
        self::clearStaleLockIfNeeded(self::getState());
        if (self::getLockData() !== null) {
            return '';
        }

        $token = wp_generate_password(24, false, false);
        $now = time();
        $created = add_option(self::LOCK_OPTION, [
            'token' => $token,
            'run_id' => $runId,
            'created_at' => $now,
            'heartbeat_at' => $now,
            'phase' => 'acquired',
        ], '', false);

        if (!$created) {
            return '';
        }

        delete_transient(self::LOCK_TRANSIENT);

        return $token;
    }

    /**
     * Determines whether lock active.
     */
    private static function isLockActive(): bool
    {
        $state = self::getState();
        if (self::clearStaleLockIfNeeded($state)) {
            return false;
        }

        return self::getLockData() !== null;
    }

    /**
     * @return array{token:string,run_id:string,created_at:int,heartbeat_at:int,phase:string,storage:string}|null
     */
    private static function getLockData(): ?array
    {
        $current = get_option(self::LOCK_OPTION, null);

        if (is_array($current)) {
            $token = (string)($current['token'] ?? '');
            if ($token !== '') {
                return [
                    'token' => $token,
                    'run_id' => (string)($current['run_id'] ?? ''),
                    'created_at' => isset($current['created_at']) ? (int)$current['created_at'] : 0,
                    'heartbeat_at' => isset($current['heartbeat_at']) ? (int)$current['heartbeat_at'] : 0,
                    'phase' => (string)($current['phase'] ?? ''),
                    'storage' => 'option',
                ];
            }
        }

        if (is_string($current) && $current !== '') {
            return [
                'token' => $current,
                'run_id' => '',
                'created_at' => 0,
                'heartbeat_at' => 0,
                'phase' => '',
                'storage' => 'option',
            ];
        }

        $current = get_transient(self::LOCK_TRANSIENT);

        if (is_array($current)) {
            $token = (string)($current['token'] ?? '');
            if ($token === '') {
                return null;
            }

            return [
                'token' => $token,
                'run_id' => (string)($current['run_id'] ?? ''),
                'created_at' => isset($current['created_at']) ? (int)$current['created_at'] : 0,
                'heartbeat_at' => isset($current['heartbeat_at'])
                    ? (int)$current['heartbeat_at']
                    : (isset($current['created_at']) ? (int)$current['created_at'] : 0),
                'phase' => (string)($current['phase'] ?? 'legacy'),
                'storage' => 'transient',
            ];
        }

        if (is_string($current) && $current !== '') {
            return [
                'token' => $current,
                'run_id' => '',
                'created_at' => 0,
                'heartbeat_at' => 0,
                'phase' => 'legacy',
                'storage' => 'transient',
            ];
        }

        return null;
    }

    /**
     * Clear a lock only when its heartbeat has been silent for the full stale window.
     *
     * @param array<string,mixed> $state
     */
    private static function clearStaleLockIfNeeded(array $state): bool
    {
        $lock = self::getLockData();
        if ($lock === null) {
            return false;
        }

        $now = time();
        if (!SyncRuntimePolicy::isLockStale($lock, $now)) {
            return false;
        }

        $latest = self::getLockData();
        if ($latest === null
            || !hash_equals((string)$lock['token'], (string)$latest['token'])
            || (int)$lock['heartbeat_at'] !== (int)$latest['heartbeat_at']
        ) {
            return false;
        }

        self::deleteLock($latest);

        if ((string)($state['status'] ?? '') === 'running') {
            $state['message'] = 'Recovered a stale sync lock. Processing will continue now.';
            $state['current_phase'] = 'stale_lock_recovered';
            update_option(self::OPTION_STATE, $state, false);
        }

        self::debug('stale_lock_recovered', [
            'run_id' => (string)($lock['run_id'] ?? ''),
            'phase' => (string)($lock['phase'] ?? ''),
            'heartbeat_age_seconds' => max(0, $now - max((int)$lock['created_at'], (int)$lock['heartbeat_at'])),
        ]);

        return true;
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function getActiveLockMessage(array $state): string
    {
        $lock = self::getLockData();
        if ($lock === null) {
            return 'Synchronization is waiting for the next available worker.';
        }

        $lastActivityAt = max((int)$lock['created_at'], (int)$lock['heartbeat_at']);
        $age = $lastActivityAt > 0 ? max(0, time() - $lastActivityAt) : 0;
        $phase = trim((string)($lock['phase'] ?? ''));

        return sprintf(
            'A synchronization batch is active%s. Last heartbeat: %d seconds ago.',
            $phase !== '' ? ' (' . $phase . ')' : '',
            $age
        );
    }

    /**
     * Releases lock.
     */
    private static function releaseLock(string $token): void
    {
        if ($token === '') {
            return;
        }

        $current = self::getLockData();
        if (is_array($current) && hash_equals($token, (string)($current['token'] ?? ''))) {
            self::deleteLock($current);
        }
    }

    /**
     * @param array{storage?:string} $lock
     */
    private static function deleteLock(array $lock): void
    {
        if ((string)($lock['storage'] ?? '') === 'option') {
            delete_option(self::LOCK_OPTION);
            return;
        }

        delete_transient(self::LOCK_TRANSIENT);
    }

    /**
     * Refreshes lock.
     */
    private static function heartbeatLock(string $token, string $runId, string $phase): bool
    {
        $lock = self::getLockData();
        if ($lock === null
            || !hash_equals($token, (string)$lock['token'])
            || !hash_equals($runId, (string)$lock['run_id'])
            || !self::isCurrentRunActive($runId)
        ) {
            return false;
        }

        $now = time();
        $payload = [
            'token' => $token,
            'run_id' => $runId,
            'created_at' => (int)$lock['created_at'],
            'heartbeat_at' => $now,
            'phase' => $phase,
        ];

        if ((string)$lock['storage'] === 'option') {
            update_option(self::LOCK_OPTION, $payload, false);
        } else {
            set_transient(self::LOCK_TRANSIENT, $payload, SyncRuntimePolicy::LOCK_STALE_SECONDS * 2);
        }

        $state = self::getState();
        if (!self::isCurrentRunActive($runId, $state)) {
            $latest = self::getLockData();
            if ($latest !== null && hash_equals($token, (string)$latest['token'])) {
                self::deleteLock($latest);
            }
            return false;
        }

        $state['last_run_at'] = $now;
        $state['current_phase'] = $phase;
        update_option(self::OPTION_STATE, $state, false);

        return true;
    }

    /** @param array<string,mixed>|null $state */
    private static function isCurrentRunActive(string $runId, ?array $state = null): bool
    {
        if ($runId === '') {
            return false;
        }

        $state = $state ?? self::getState();
        $currentRunId = (string)($state['run_id'] ?? '');

        return (string)($state['status'] ?? '') === 'running'
            && $currentRunId !== ''
            && hash_equals($currentRunId, $runId);
    }

    /** @param array<string,mixed> $context */
    private static function beginPhase(
        string $lockToken,
        string $runId,
        string $phase,
        float $batchStartedAt,
        array $context = []
    ): void {
        if (!self::heartbeatLock($lockToken, $runId, $phase)) {
            throw new \RuntimeException('Sync run was stopped or superseded.');
        }

        self::debug('tick_phase_started', array_merge([
            'run_id' => $runId,
            'phase' => $phase,
            'elapsed_ms' => SyncRuntimePolicy::elapsedMilliseconds($batchStartedAt, microtime(true)),
        ], $context));
    }

    /** @param array<string,mixed> $context */
    private static function finishPhase(string $phase, float $batchStartedAt, array $context = []): void
    {
        self::debug('tick_phase_finished', array_merge([
            'phase' => $phase,
            'elapsed_ms' => SyncRuntimePolicy::elapsedMilliseconds($batchStartedAt, microtime(true)),
        ], $context));
    }

    /** @phpstan-impure */
    private static function hasBatchTimeBudgetExpired(float $batchStartedAt): bool
    {
        return SyncRuntimePolicy::shouldYield($batchStartedAt, microtime(true));
    }

    /**
     * Handles admin status.
     */
    public static function handleAdminStatus(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'maradigma_boat_sync_admin')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $state = self::getState();
        self::registerFatalLogger('ajax_status', (string)($state['run_id'] ?? ''));
        self::debug('ajax_status', ['state' => self::summarizeState($state)]);
        wp_send_json_success(self::getUiStatusPayload());
    }

    /**
     * Handles admin pump.
     */
    public static function handleAdminPump(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'maradigma_boat_sync_admin')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        $state = self::getState();
        self::registerFatalLogger('ajax_pump', (string)($state['run_id'] ?? ''));
        if ((string)($state['status'] ?? '') === 'running') {
            try {
                self::debug('ajax_pump_before_tick', ['state' => self::summarizeState($state)]);
                self::clearStaleLockIfNeeded($state);
                self::tick();
                self::debug('ajax_pump_after_tick', ['state' => self::summarizeState(self::getState())]);
            } catch (\Throwable $e) {
                $currentState = self::getState();
                $runId = (string)($state['run_id'] ?? '');
                if (self::isCurrentRunActive($runId, $currentState)) {
                    $currentState['status'] = 'error';
                    $currentState['current_phase'] = 'error';
                    $currentState['message'] = $e->getMessage();
                    update_option(self::OPTION_STATE, $currentState, false);
                }
                self::error('ajax_pump_exception', [
                    'run_id' => $runId,
                    'exception' => $e,
                    'state' => self::summarizeState($currentState),
                ]);
            }
        }

        wp_send_json_success(self::getUiStatusPayload());
    }

    /**
     * Handles admin stop.
     */
    public static function handleAdminStop(): void
    {
        self::registerFatalLogger('ajax_stop');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'maradigma_boat_sync_admin')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }

        self::stop();
        self::debug('ajax_stop', ['state' => self::summarizeState(self::getState())]);
        wp_send_json_success(self::getUiStatusPayload());
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function summarizeState(array $state): array
    {
        return [
            'run_id' => (string)($state['run_id'] ?? ''),
            'status' => (string)($state['status'] ?? ''),
            'started_at' => (int)($state['started_at'] ?? 0),
            'last_run_at' => (int)($state['last_run_at'] ?? 0),
            'current_phase' => (string)($state['current_phase'] ?? ''),
            'offset' => (int)($state['offset'] ?? 0),
            'limit' => (int)($state['limit'] ?? 0),
            'boats_total' => (int)($state['boats_total'] ?? 0),
            'boats_done' => (int)($state['boats_done'] ?? 0),
            'message' => (string)($state['message'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function debug(string $message, array $context = []): void
    {
        if (!class_exists(Debugger::class)) {
            return;
        }

        Debugger::log(self::DEBUG_CHANNEL, $message, $context);
    }

    /**
     * Record a synchronization failure regardless of verbose debug mode.
     *
     * @param array<string,mixed> $context Structured diagnostic context.
     */
    private static function error(string $message, array $context = []): void
    {
        if (!class_exists(Debugger::class)) {
            return;
        }

        Debugger::error(self::DEBUG_CHANNEL, $message, $context);
    }

    /**
     * Registers a shutdown handler that records fatal boat synchronization errors.
     */
    private static function registerFatalLogger(string $stage, string $runId = ''): void
    {
        static $registered = [];
        $registrationKey = $stage . ':' . $runId;
        if (isset($registered[$registrationKey])) {
            return;
        }

        $registered[$registrationKey] = true;

        register_shutdown_function(static function () use ($stage, $runId): void {
            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }

            $type = (int)($error['type'] ?? 0);
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
            if (!in_array($type, $fatalTypes, true)) {
                return;
            }

            $state = self::getState();
            $isMatchingRun = $runId === '' || hash_equals((string)($state['run_id'] ?? ''), $runId);
            if ((string)($state['status'] ?? '') === 'running' && $isMatchingRun) {
                $state['status'] = 'error';
                $state['current_phase'] = 'error';
                $state['message'] = 'Fatal error during boat sync: ' . (string)($error['message'] ?? '');
                update_option(self::OPTION_STATE, $state, false);
            }

            self::error('shutdown_fatal', [
                'stage' => $stage,
                'run_id' => $runId,
                'error' => [
                    'type' => $type,
                    'message' => (string)($error['message'] ?? ''),
                    'file' => (string)($error['file'] ?? ''),
                    'line' => (int)($error['line'] ?? 0),
                ],
                'state' => self::summarizeState($state),
            ]);
        });
    }

    /**
     * @return array<string,mixed>
     */
    public static function getUiStatusPayload(): array
    {
        $state = self::getState();
        $nextCronAt = (int)wp_next_scheduled(self::CRON_HOOK);

        if ((string)($state['status'] ?? '') === 'running' && $nextCronAt <= 0) {
            if (self::clearStaleLockIfNeeded($state)) {
                $scheduled = wp_schedule_single_event(time() + 2, self::CRON_HOOK);
                if ($scheduled === false || is_wp_error($scheduled)) {
                    $state = self::getState();
                    $state['message'] = is_wp_error($scheduled)
                        ? 'Recovered a stale lock, but WP-Cron could not be rescheduled: ' . $scheduled->get_error_message()
                        : 'Recovered a stale lock. The admin pump will keep trying while this page is open.';
                } else {
                    $nextCronAt = (int)wp_next_scheduled(self::CRON_HOOK);
                    $state = self::getState();
                    $state['message'] = 'Recovered a stale lock and rescheduled WP-Cron. Processing will continue now.';
                }
            } elseif (self::isLockActive()) {
                $state['message'] = self::getActiveLockMessage($state);
            } else {
                $scheduled = wp_schedule_single_event(time() + 2, self::CRON_HOOK);
                if ($scheduled === false || is_wp_error($scheduled)) {
                    $state['message'] = is_wp_error($scheduled)
                        ? 'WP-Cron could not be rescheduled: ' . $scheduled->get_error_message()
                        : 'WP-Cron is not scheduled. The admin pump will keep trying while this page is open.';
                } else {
                    $nextCronAt = (int)wp_next_scheduled(self::CRON_HOOK);
                    $state['message'] = 'WP-Cron was missing and has been rescheduled. The admin pump is also processing.';
                }
            }

            update_option(self::OPTION_STATE, $state, false);
        }

        return [
            'state'           => $state,
            'next_cron_at'    => $nextCronAt,
            'next_cron_label' => $nextCronAt > 0
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $nextCronAt)
                : __('Not scheduled', 'maradigma'),
            'last_run_label'  => !empty($state['last_run_at'])
                ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int)$state['last_run_at'])
                : __('Never', 'maradigma'),
        ];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public static function syncNow(array $options = []): array
    {
        self::start($options);

        return [
            'success' => true,
            'mode'    => 'batched',
            'message' => 'Sync scheduled via WP-Cron. Check state via BoatSyncService::getState().',
            'state'   => self::getState(),
        ];
    }

    // ─────────────────────────────────────────────
    // Images: attach cached only (no downloads)
    // ─────────────────────────────────────────────

    /**
     * Attach locally cached images (BoatImagesSyncService) to this Boat CPT post.
     *
     * IMPORTANT:
     * - MUST NOT download anything.
     * - BoatImagesSyncService is the source of truth for cover + ordering.
     */
    private static function syncBoatImages(int $postId, string $boatId): void
    {
        if ($postId <= 0) return;

        $boatId = trim($boatId);
        if ($boatId === '') {
            delete_post_meta($postId, self::META_GALLERY_ATTACHMENT_IDS);
            return;
        }

        // ✅ Gallery (ordered deterministically by BoatImagesSyncService)
        $attachmentIds = [];
        if (\class_exists(\Maradigma\BoatImagesSyncService::class)
            && \method_exists(\Maradigma\BoatImagesSyncService::class, 'getOrderedAttachmentIdsByBoatId')
        ) {
            $attachmentIds = \Maradigma\BoatImagesSyncService::getOrderedAttachmentIdsByBoatId($boatId, 50);
        }

        if (empty($attachmentIds)) {
            delete_post_meta($postId, self::META_GALLERY_ATTACHMENT_IDS);
        } else {
            update_post_meta($postId, self::META_GALLERY_ATTACHMENT_IDS, array_values($attachmentIds));
        }

        // ✅ Featured: use cached cover attachment id (service is source of truth)
        $coverId = 0;
        if (\class_exists(\Maradigma\BoatImagesSyncService::class)
            && \method_exists(\Maradigma\BoatImagesSyncService::class, 'getCoverAttachmentIdByBoatId')
        ) {
            $coverId = (int)\Maradigma\BoatImagesSyncService::getCoverAttachmentIdByBoatId($boatId);
        }

        if ($coverId > 0) {
            self::setFeaturedImageOnce($postId, $coverId);
        } elseif (!empty($attachmentIds)) {
            // fallback strictly WP-only: first of the ordered gallery
            self::setFeaturedImageOnce($postId, (int)$attachmentIds[0]);
        }
    }

    /**
     * Assigns the featured image when the post does not already have one.
     */
    private static function setFeaturedImageOnce(int $postId, int $attachmentId): void
    {
        if ($postId <= 0 || $attachmentId <= 0) return;

        $currentThumb = (int)get_post_thumbnail_id($postId);
        if ($currentThumb > 0) return;

        set_post_thumbnail($postId, $attachmentId);
        update_post_meta($postId, self::META_FEATURED_ATTACHMENT_ID, (string)$attachmentId);
    }

    // ─────────────────────────────────────────────
    // Languages / options helpers
    // ─────────────────────────────────────────────

    /** @return array{0:string[],1:string} [$langs, $sourceLang] */
    private static function resolveLanguages(): array
    {
        $settings = SettingsPage::getSettings();

        $pluginFallback = strtolower(trim((string)($settings['default_language'] ?? 'en')));
        if ($pluginFallback === '') $pluginFallback = 'en';

        $sourceLang = MultilangAdapter::getDefaultLanguage();
        $sourceLang = strtolower(trim((string)$sourceLang));
        if ($sourceLang === '') $sourceLang = $pluginFallback;
        if ($sourceLang === '') $sourceLang = 'en';

        $langs = MultilangAdapter::getActiveLanguages();
        if (empty($langs)) $langs = [$sourceLang];

        $langs = array_values(array_unique(array_merge([$sourceLang], $langs)));

        return [$langs, $sourceLang];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private static function makeInitialCleanupState(array $options): array
    {
        return [
            'action'            => (string)($options['cleanup_obsolete'] ?? 'none'),
            'status'            => 'pending',
            'valid_boats'       => 0,
            'candidates'        => 0,
            'changed'           => 0,
            'failed'            => 0,
            'skipped_protected' => 0,
            'images_deleted'    => 0,
            'images_failed'     => 0,
            'message'           => '',
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function maybeCleanupObsoleteBoatPosts(array $state): array
    {
        $options = is_array($state['options'] ?? null) ? (array)$state['options'] : self::normalizeOptions([]);
        $action = sanitize_key((string)($options['cleanup_obsolete'] ?? 'none'));
        if (!in_array($action, ['none', 'trash', 'draft', 'delete'], true)) {
            $action = 'none';
        }

        if (!isset($state['cleanup']) || !is_array($state['cleanup'])) {
            $state['cleanup'] = self::makeInitialCleanupState($options);
        }
        $state['cleanup']['action'] = $action;

        if ($action === 'none') {
            $state['cleanup']['status'] = 'skipped';
            $state['cleanup']['message'] = 'No obsolete-page cleanup selected.';
            return $state;
        }

        if ($action === 'delete' && empty($options['cleanup_delete_confirm'])) {
            $state['cleanup']['status'] = 'skipped';
            $state['cleanup']['message'] = 'Permanent delete was selected without explicit confirmation.';
            return $state;
        }

        $validBoatIds = array_values(array_unique(array_filter(array_map(static function ($id): string {
            return trim((string)$id);
        }, (array)($state['api_boat_ids'] ?? [])))));

        $state['cleanup']['valid_boats'] = count($validBoatIds);

        if (empty($state['api_list_valid']) || empty($validBoatIds)) {
            $state['cleanup']['status'] = 'skipped';
            $state['cleanup']['message'] = 'Cleanup skipped because the API list was empty or not fully trusted.';
            return $state;
        }

        $deleteImages = $action === 'delete' && !empty($options['cleanup_delete_images']);
        $state['cleanup'] = array_merge($state['cleanup'], self::cleanupObsoleteManagedBoatPosts($validBoatIds, $action, $deleteImages));
        return $state;
    }

    /**
     * @param string[] $validBoatIds
     * @return array<string,mixed>
     */
    private static function cleanupObsoleteManagedBoatPosts(array $validBoatIds, string $action, bool $deleteImages = false): array
    {
        $validMap = array_fill_keys(array_map('strval', $validBoatIds), true);
        $stats = [
            'action'            => $action,
            'status'            => 'done',
            'valid_boats'       => count($validMap),
            'candidates'        => 0,
            'changed'           => 0,
            'failed'            => 0,
            'skipped_protected' => 0,
            'images_deleted'    => 0,
            'images_failed'     => 0,
            'message'           => '',
        ];

        $query = new \WP_Query([
            'post_type'      => BoatPostType::POST_TYPE,
            'post_status'    => ['publish', 'future', 'draft', 'pending', 'private'],
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => self::META_BOAT_ID,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'   => self::META_MANAGED,
                    'value' => '1',
                ],
            ],
        ]);

        $postIds = is_array($query->posts) ? $query->posts : [];
        foreach ($postIds as $postId) {
            $postId = (int)$postId;
            if ($postId <= 0) {
                continue;
            }

            $boatId = trim((string)get_post_meta($postId, self::META_BOAT_ID, true));
            if ($boatId === '' || isset($validMap[$boatId])) {
                continue;
            }

            if ((bool)get_post_meta($postId, self::META_DISABLE_SYNC, true)) {
                $stats['skipped_protected'] = (int)$stats['skipped_protected'] + 1;
                continue;
            }

            $stats['candidates'] = (int)$stats['candidates'] + 1;

            if ($deleteImages) {
                $imageStats = self::deleteCachedImagesForBoatId($boatId);
                $stats['images_deleted'] = (int)$stats['images_deleted'] + (int)$imageStats['deleted'];
                $stats['images_failed'] = (int)$stats['images_failed'] + (int)$imageStats['failed'];
            }

            $ok = false;
            if ($action === 'trash') {
                $ok = (bool)wp_trash_post($postId);
            } elseif ($action === 'draft') {
                $result = wp_update_post([
                    'ID'          => $postId,
                    'post_status' => 'draft',
                ], true);
                $ok = !is_wp_error($result) && (int)$result > 0;
            } elseif ($action === 'delete') {
                $ok = (bool)wp_delete_post($postId, true);
            }

            if ($ok) {
                $stats['changed'] = (int)$stats['changed'] + 1;
            } else {
                $stats['failed'] = (int)$stats['failed'] + 1;
            }
        }

        $stats['message'] = sprintf(
            'Obsolete-page cleanup finished: %s, candidates %d, changed %d, failed %d.',
            $action,
            (int)$stats['candidates'],
            (int)$stats['changed'],
            (int)$stats['failed']
        );

        return $stats;
    }

    /**
     * @return array{deleted:int,failed:int}
     */
    private static function deleteCachedImagesForBoatId(string $boatId): array
    {
        $boatId = trim($boatId);
        if ($boatId === '') {
            return ['deleted' => 0, 'failed' => 0];
        }

        if (!function_exists('wp_delete_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $query = new \WP_Query([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'lang'             => '',
            'meta_query'       => [
                [
                    'key'   => self::META_BOAT_ID,
                    'value' => $boatId,
                ],
            ],
        ]);

        $deleted = 0;
        $failed = 0;
        $ids = is_array($query->posts) ? $query->posts : [];
        foreach ($ids as $attachmentId) {
            $attachmentId = (int)$attachmentId;
            if ($attachmentId <= 0) {
                continue;
            }

            if (wp_delete_attachment($attachmentId, true)) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private static function normalizeOptions(array $options): array
    {
        $defaults = [
            'update_post_title_slug' => true,
            'update_payload'         => true,
            'sync_images'            => true,
            'yoast_mode'             => 'fix_multilang',
            'layout_builder'         => self::getConfiguredLayoutBuilder(),
            'elementor_mode'         => 'seed_missing',
            'gutenberg_mode'         => 'seed_missing',
            'wpbakery_mode'         => 'seed_missing',
            'cleanup_obsolete'       => 'none',
            'cleanup_delete_confirm' => false,
            'cleanup_delete_images'  => false,
        ];

        $options = array_merge($defaults, is_array($options) ? $options : []);

        $options['update_post_title_slug'] = !empty($options['update_post_title_slug']);
        $options['update_payload']         = !empty($options['update_payload']);
        $options['sync_images']            = !empty($options['sync_images']);

        $ym = (string)($options['yoast_mode'] ?? 'fix_multilang');
        $options['yoast_mode'] = in_array($ym, ['fix_multilang', 'skip'], true) ? $ym : 'fix_multilang';

        $builder = sanitize_key((string)($options['layout_builder'] ?? self::getConfiguredLayoutBuilder()));
        $options['layout_builder'] = in_array($builder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $builder : 'elementor';

        $em = (string)($options['elementor_mode'] ?? 'seed_missing');
        $options['elementor_mode'] = in_array($em, ['seed_missing', 'overwrite', 'skip'], true) ? $em : 'seed_missing';

        $gm = (string)($options['gutenberg_mode'] ?? 'seed_missing');
        $options['gutenberg_mode'] = in_array($gm, ['seed_missing', 'overwrite', 'force', 'skip'], true) ? $gm : 'seed_missing';

        $wm = (string)($options['wpbakery_mode'] ?? 'seed_missing');
        $options['wpbakery_mode'] = in_array($wm, ['seed_missing', 'overwrite', 'force', 'skip'], true) ? $wm : 'seed_missing';

        $cleanup = sanitize_key((string)($options['cleanup_obsolete'] ?? 'none'));
        $options['cleanup_obsolete'] = in_array($cleanup, ['none', 'trash', 'draft', 'delete'], true) ? $cleanup : 'none';
        $options['cleanup_delete_confirm'] = !empty($options['cleanup_delete_confirm']);
        $options['cleanup_delete_images'] = !empty($options['cleanup_delete_images']);

        if ($options['cleanup_obsolete'] === 'delete' && empty($options['cleanup_delete_confirm'])) {
            $options['cleanup_obsolete'] = 'none';
            $options['cleanup_delete_images'] = false;
        }

        if ($options['cleanup_obsolete'] !== 'delete') {
            $options['cleanup_delete_images'] = false;
        }

        return $options;
    }

    // ─────────────────────────────────────────────
    // Post lookup
    // ─────────────────────────────────────────────

    /**
     * Finds a synchronized boat post for an external boat ID and language.
     */
    private static function findPostIdByBoatIdAndLang(string $boatId, string $lang): int
    {
        $q = new \WP_Query([
            'post_type'      => BoatPostType::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 50,
            // The lookup must cross language filters; each candidate is validated below.
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters
            'suppress_filters' => true,
            'meta_query'     => [
                [
                    'key'   => self::META_BOAT_ID,
                    'value' => $boatId,
                ],
            ],
        ]);

        if (empty($q->posts)) return 0;

        $lang = strtolower(trim($lang));
        $provider = MultilangAdapter::detectProvider();

        foreach ($q->posts as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;

            $postLanguage = MultilangAdapter::getPostLanguage($pid);
            if ($postLanguage === $lang) {
                return $pid;
            }

            if ($provider === '' && $postLanguage === '') {
                return $pid;
            }
        }

        return 0;
    }

    // ─────────────────────────────────────────────
    // Layout builder / Gutenberg helpers
    // ─────────────────────────────────────────────

    /**
     * Returns the configured boat layout builder.
     */
    private static function getConfiguredLayoutBuilder(): string
    {
        $settings = \Maradigma\SettingsPage::getSettings();
        $builder = sanitize_key((string)($settings['boat_layout_builder'] ?? 'elementor'));

        return in_array($builder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $builder : 'elementor';
    }

    /**
     * Loads the Gutenberg integration when it is available.
     */
    private static function loadGutenbergIntegration(): bool
    {
        if (class_exists(GutenbergIntegration::class)) {
            return true;
        }

        $file = trailingslashit(MARADIGMA_PLUGIN_DIR) . 'integrations/Gutenberg/GutenbergIntegration.php';
        if (is_readable($file)) {
            require_once $file;
        }

        return class_exists(GutenbergIntegration::class);
    }

    /**
     * Loads the WPBakery integration when it is available.
     */
    private static function loadWPBakeryIntegration(): bool
    {
        if (class_exists(WPBakeryIntegration::class)) {
            return true;
        }

        $file = trailingslashit(MARADIGMA_PLUGIN_DIR) . 'integrations/WPBakery/WPBakeryIntegration.php';
        if (is_readable($file)) {
            require_once $file;
        }

        return class_exists(WPBakeryIntegration::class);
    }

    /**
     * Ensures WPBakery master template is available and correctly configured.
     */
    public static function ensureWPBakeryMasterTemplate(): int
    {
        if (!self::loadWPBakeryIntegration()) {
            return 0;
        }

        return WPBakeryIntegration::ensureWPBakeryMasterTemplate();
    }

    /**
     * Seeds WPBakery master content into a synchronized boat post.
     */
    private static function seedWPBakeryToBoatPost(int $postId, string $mode = 'seed_missing'): void
    {
        if ($postId <= 0 || !self::loadWPBakeryIntegration()) {
            return;
        }

        $mode = sanitize_key($mode);
        if (!in_array($mode, ['seed_missing', 'overwrite', 'force'], true)) {
            $mode = 'seed_missing';
        }

        WPBakeryIntegration::applyWPBakeryTemplateToBoatPost($postId, $mode);
    }

    /**
     * Ensures Gutenberg master template is available and correctly configured.
     */
    public static function ensureGutenbergMasterTemplate(): int
    {
        if (!self::loadGutenbergIntegration()) {
            return 0;
        }

        return GutenbergIntegration::ensureGutenbergMasterTemplate();
    }

    /**
     * Seeds Gutenberg master content into a synchronized boat post.
     */
    private static function seedGutenbergToBoatPost(int $postId, string $mode = 'seed_missing'): void
    {
        if ($postId <= 0 || !self::loadGutenbergIntegration()) {
            return;
        }

        $mode = sanitize_key($mode);
        if (!in_array($mode, ['seed_missing', 'overwrite', 'force'], true)) {
            $mode = 'seed_missing';
        }

        GutenbergIntegration::applyGutenbergTemplateToBoatPost($postId, $mode);
    }

    // ─────────────────────────────────────────────
    // Elementor helpers (as you had them)
    // ─────────────────────────────────────────────

    /**
     * Determines whether Elementor active.
     */
    private static function isElementorActive(): bool
    {
        return did_action('elementor/loaded') || class_exists('\\Elementor\\Plugin');
    }

    /**
     * Determines whether overwrite Elementor.
     */
    private static function shouldOverwriteElementor(int $postId, int $templateId): bool
    {
        if ($templateId <= 0 || !get_post($templateId)) return false;

        $isCustom = (bool)get_post_meta($postId, '_maradigma_elementor_custom_layout', true);
        if ($isCustom) return false;

        return self::isElementorActive();
    }

    /**
     * Seeds Elementor master data into a synchronized boat post.
     */
    private static function seedElementorToBoatPost(int $postId, int $templateId): void
    {
        if ($templateId <= 0 || !get_post($templateId) || !self::isElementorActive()) return;

        $tplJson = (string)get_post_meta($templateId, '_elementor_data', true);
        if ($tplJson === '') return;

        update_post_meta($postId, '_elementor_data', wp_slash($tplJson));
        update_post_meta($postId, '_elementor_edit_mode', 'builder');

        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($postId, '_elementor_version', ELEMENTOR_VERSION);
        }

        $storedPostData = (string)get_post_meta($postId, '_elementor_data', true);
        $storedTplData  = (string)get_post_meta($templateId, '_elementor_data', true);

        update_post_meta($postId, self::META_LAYOUT_BUILDER, 'elementor');
        update_post_meta($postId, self::META_ELEMENTOR_SEEDED, '1');
        update_post_meta($postId, self::META_ELEMENTOR_DATA_HASH, md5($storedPostData));
        update_post_meta($postId, self::META_ELEMENTOR_TEMPLATE_HASH, md5($storedTplData));

        self::regenerateElementorCss($postId);
    }

    /**
     * Regenerates Elementor CSS.
     */
    private static function regenerateElementorCss(int $postId): void
    {
        if ($postId <= 0) return;
        if (!class_exists('\Elementor\Plugin')) return;

        try {
            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                $cssFile = new \Elementor\Core\Files\CSS\Post($postId);
                $cssFile->update();
            }
        } catch (\Throwable) {
            // no-op
        }
    }

    /**
     * Default Elementor structure for the master template.
     * Uses your custom widgets.
     */
    private static function getElementorBoatTemplateData(): array
    {
        $sid = self::randomElementorId();
        $cid = self::randomElementorId();

        return [
            [
                'id' => $sid,
                'elType' => 'section',
                'settings' => [
                    'structure' => '10',
                ],
                'elements' => [
                    [
                        'id' => $cid,
                        'elType' => 'column',
                        'settings' => [
                            '_column_size' => 100,
                        ],
                        'elements' => [
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_title',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_gallery',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_price',
                                'settings' => [
                                    'show_label' => 'yes',
                                    'label_from' => 'From',
                                ],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_specs',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_additional_services',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_description',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_equipments',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_included',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_not_included',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_pdf_download',
                                'settings' => [],
                                'elements' => [],
                            ],
                            [
                                'id' => self::randomElementorId(),
                                'elType' => 'widget',
                                'widgetType' => 'maradigma_boat_book_now',
                                'settings' => [],
                                'elements' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Generates a stable-format random Elementor element identifier.
     */
    private static function randomElementorId(): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < 7; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    /**
     * Ensures Elementor master template is available and correctly configured.
     */
    public static function ensureElementorMasterTemplate(): int
    {
        if (self::$ensuringElementorMasterTemplate) {
            return (int) get_option('maradigma_elementor_master_template_id', 0);
        }

        self::$ensuringElementorMasterTemplate = true;

        try {
            return self::ensureElementorMasterTemplateUnlocked();
        } finally {
            self::$ensuringElementorMasterTemplate = false;
        }
    }

    /**
     * Create or repair the Elementor master template while the re-entry guard is held.
     */
    private static function ensureElementorMasterTemplateUnlocked(): int
    {
        $oldKey = 'maradigma_elementor_master_page_id';
        $newKey = 'maradigma_elementor_master_template_id';

        $templateId = (int)get_option($newKey);
        if ($templateId > 0 && !get_post($templateId)) {
            delete_option($newKey);
            $templateId = 0;
        }

        if ($templateId === 0) {
            $oldId = (int)get_option($oldKey);
            if ($oldId > 0 && get_post($oldId) && self::isElementorActive()) {
                $oldData = (string)get_post_meta($oldId, '_elementor_data', true);
                if ($oldData !== '') {
                    $migrated = wp_insert_post([
                        'post_title'  => 'Maradigma - Boat Template (Elementor)',
                        'post_type'   => 'elementor_library',
                        'post_status' => 'publish',
                    ], true);

                    if (!is_wp_error($migrated) && (int)$migrated > 0) {
                        $templateId = (int)$migrated;
                        update_post_meta($templateId, '_elementor_data', wp_slash($oldData));
                        update_post_meta($templateId, '_elementor_edit_mode', 'builder');
                        update_post_meta($templateId, '_elementor_template_type', 'page');
                        update_post_meta($templateId, '_elementor_document_type', 'page');
                        if (defined('ELEMENTOR_VERSION')) {
                            update_post_meta($templateId, '_elementor_version', ELEMENTOR_VERSION);
                        }
                        update_option($newKey, $templateId);
                    }
                }
            }
        }

        if (!self::isElementorActive()) {
            return 0;
        }

        if ($templateId > 0) {
            $p = get_post($templateId);
            if ($p && $p->post_type === 'elementor_library') {
                $existingJson = (string)get_post_meta($templateId, '_elementor_data', true);
                if ($existingJson === '') {
                    $data = self::getElementorBoatTemplateData();
                    $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (is_string($json)) {
                        update_post_meta($templateId, '_elementor_data', wp_slash($json));
                    }
                }

                update_post_meta($templateId, '_elementor_edit_mode', 'builder');
                update_post_meta($templateId, '_elementor_template_type', 'page');
                update_post_meta($templateId, '_elementor_document_type', 'page');
                if (defined('ELEMENTOR_VERSION')) {
                    update_post_meta($templateId, '_elementor_version', ELEMENTOR_VERSION);
                }
                update_option($newKey, $templateId);
                return $templateId;
            }

            delete_option($newKey);
            $templateId = 0;
        }

        $templateId = (int)wp_insert_post([
            'post_title'  => 'Maradigma - Boat Template (Elementor)',
            'post_type'   => 'elementor_library',
            'post_status' => 'publish',
        ], true);

        if ($templateId <= 0 || is_wp_error($templateId)) {
            return 0;
        }

        $data = self::getElementorBoatTemplateData();
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json)) {
            update_post_meta($templateId, '_elementor_data', wp_slash($json));
        }

        update_post_meta($templateId, '_elementor_edit_mode', 'builder');
        update_post_meta($templateId, '_elementor_template_type', 'page');
        update_post_meta($templateId, '_elementor_document_type', 'page');
        if (defined('ELEMENTOR_VERSION')) {
            update_post_meta($templateId, '_elementor_version', ELEMENTOR_VERSION);
        }

        update_option($newKey, $templateId);
        return $templateId;
    }
}
