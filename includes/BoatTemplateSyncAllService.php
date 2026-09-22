<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Integrations\Gutenberg\GutenbergIntegration;
use Maradigma\Integrations\WPBakery\WPBakeryIntegration;
use Maradigma\Support\Debugger;

/**
 * Applies the selected Maradigma master template to all synced boat pages in batches.
 *
 * Supported builders:
 * - Elementor: copies `_elementor_data` from the Elementor master template.
 * - Gutenberg: copies `post_content` from the editable Gutenberg master template.
 * - WPBakery: copies shortcode-based `post_content` from the editable WPBakery
 *   master template.
 *
 * The service is intentionally batched because template propagation can touch a
 * large catalog. Each builder-specific integration decides whether a boat is
 * eligible for overwrite, including custom-layout opt-out flags.
 */
final class BoatTemplateSyncAllService
{
    private const OPTION_STATE = 'maradigma_template_sync_all_state';
    private const CRON_HOOK    = 'maradigma_template_sync_all_tick';
    private const AJAX_STATUS_ACTION = 'maradigma_template_sync_status';
    private const AJAX_PUMP_ACTION   = 'maradigma_template_sync_pump';
    private const ADMIN_NONCE_ACTION = 'maradigma_template_sync_admin';
    private const DEBUG_CHANNEL = 'template-sync-all';

    private const META_LAYOUT_BUILDER = '_maradigma_layout_builder';

    // Elementor tracking
    private const META_ELEMENTOR_CUSTOM_LAYOUT   = '_maradigma_elementor_custom_layout';
    private const META_ELEMENTOR_SEEDED          = '_maradigma_elementor_seeded';
    private const META_ELEMENTOR_DATA_HASH       = '_maradigma_elementor_data_hash';
    private const META_ELEMENTOR_TEMPLATE_HASH   = '_maradigma_elementor_template_hash';

    // Gutenberg tracking
    private const META_GUTENBERG_SEEDED          = '_maradigma_gutenberg_seeded';
    private const META_GUTENBERG_CONTENT_HASH    = '_maradigma_gutenberg_content_hash';
    private const META_GUTENBERG_TEMPLATE_HASH   = '_maradigma_gutenberg_template_hash';
    private const META_GUTENBERG_CUSTOM_LAYOUT   = '_maradigma_gutenberg_custom_layout';

    private const DEFAULT_BATCH = 100;
    private const MAX_BATCH     = 500;

    /**
     * Registers the component's WordPress hooks.
     */
    public static function init(): void
    {
        \add_action(self::CRON_HOOK, [self::class, 'tick']);
        \add_action('wp_ajax_' . self::AJAX_STATUS_ACTION, [self::class, 'handleAdminStatus']);
        \add_action('wp_ajax_' . self::AJAX_PUMP_ACTION, [self::class, 'handleAdminPump']);
    }

    /**
     * Starts the background workflow.
     */
    public static function start(int $batchSize = self::DEFAULT_BATCH, ?string $layoutBuilder = null, string $mode = 'overwrite'): void
    {
        $batchSize = max(1, min(self::MAX_BATCH, $batchSize));

        $layoutBuilder = sanitize_key((string) ($layoutBuilder ?? self::getConfiguredLayoutBuilder()));
        $layoutBuilder = in_array($layoutBuilder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $layoutBuilder : 'elementor';

        $mode = sanitize_key($mode);
        if (!in_array($mode, ['overwrite', 'force'], true)) {
            $mode = 'overwrite';
        }

        $masterId = 0;
        $masterHash = '';
        $masterContent = '';

        if ($layoutBuilder === 'gutenberg') {
            if (!self::loadGutenbergIntegration()) {
                self::storeState([
                    'status'  => 'error',
                    'builder' => 'gutenberg',
                    'message' => 'Gutenberg integration not available.',
                ]);
                return;
            }

            $masterId = GutenbergIntegration::ensureGutenbergMasterTemplate();
            $masterContent = GutenbergIntegration::getGutenbergMasterTemplateContent();
            $masterHash = method_exists(GutenbergIntegration::class, 'hashGutenbergTemplateContent')
                ? GutenbergIntegration::hashGutenbergTemplateContent($masterContent)
                : md5($masterContent);

            if ($masterId <= 0 || trim($masterContent) === '') {
                self::storeState([
                    'status'  => 'error',
                    'builder' => 'gutenberg',
                    'message' => 'Gutenberg master template not found or empty.',
                ]);
                return;
            }
        } elseif ($layoutBuilder === 'wpbakery') {
            if (!self::loadWPBakeryIntegration()) {
                self::storeState([
                    'status'  => 'error',
                    'builder' => 'wpbakery',
                    'message' => 'WPBakery integration not available.',
                ]);
                return;
            }

            $masterId = WPBakeryIntegration::ensureWPBakeryMasterTemplate();
            $masterContent = WPBakeryIntegration::getWPBakeryMasterTemplateContent();
            $masterHash = WPBakeryIntegration::hashWPBakeryTemplateContent($masterContent);

            if ($masterId <= 0 || trim($masterContent) === '') {
                self::storeState([
                    'status'  => 'error',
                    'builder' => 'wpbakery',
                    'message' => 'WPBakery master template not found or empty.',
                ]);
                return;
            }
        } else {
            $masterId = (int) get_option('maradigma_elementor_master_template_id', 0);
            $p = $masterId > 0 ? get_post($masterId) : null;
            if (!$p || $p->post_type !== 'elementor_library') {
                self::storeState([
                    'status'  => 'error',
                    'builder' => 'elementor',
                    'message' => 'Elementor master template not found.',
                ]);
                return;
            }

            $masterContent = (string) get_post_meta($masterId, '_elementor_data', true);
            if ($masterContent === '') {
                self::storeState([
                    'status'  => 'error',
                    'builder' => 'elementor',
                    'message' => 'Elementor master template has empty _elementor_data.',
                ]);
                return;
            }

            $masterHash = md5($masterContent);
        }

        $state = [
            'status'         => 'running',
            'started_at'     => time(),
            'last_run_at'    => 0,
            'message'        => '',
            'builder'        => $layoutBuilder,
            'mode'           => $mode,
            'master_id'      => $masterId,
            'master_hash'    => $masterHash,
            'offset'         => 0,
            'limit'          => $batchSize,
            'boats_total'    => 0,
            'pages_total'    => 0,
            'languages_on_pages' => 0,
            'language_codes' => [],
            'pages_scanned'  => 0,
            'pages_updated'  => 0,
            'pages_skipped_custom' => 0,
            'pages_skipped'  => 0,
            // Legacy aliases kept for existing admin JS/API consumers. These now mirror page counters.
            'boats_scanned'  => 0,
            'boats_updated'  => 0,
            'boats_skipped_custom' => 0,
            'boats_skipped'  => 0,
            'pages_failed'   => 0,
            'skip_reasons'   => [],
            'skipped_samples' => [],
            'boats_failed'   => 0,
        ];

        self::storeState($state);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 2, self::CRON_HOOK);
        }
    }

    /**
     * Stops the background workflow and clears pending work.
     */
    public static function stop(): void
    {
        $state = self::getState();
        $state['status']  = 'stopped';
        $state['message'] = 'Stopped by user';
        self::storeState($state);

        $ts = wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /**
     * Handles admin status.
     */
    public static function handleAdminStatus(): void
    {
        self::registerFatalLogger('ajax_status');
        self::checkAdminAjaxRequest();
        self::debug('ajax_status', ['state' => self::summarizeState(self::getState())]);
        \wp_send_json_success(self::getUiStatusPayload());
    }

    /**
     * Handles admin pump.
     */
    public static function handleAdminPump(): void
    {
        self::registerFatalLogger('ajax_pump');
        self::checkAdminAjaxRequest();

        $state = self::getState();
        if (($state['status'] ?? '') === 'running') {
            try {
                self::debug('ajax_pump_before_tick', ['state' => self::summarizeState($state)]);
                self::tick();
                self::debug('ajax_pump_after_tick', ['state' => self::summarizeState(self::getState())]);
            } catch (\Throwable $e) {
                $state = self::getState();
                $state['status'] = 'error';
                $state['message'] = $e->getMessage();
                self::storeState($state);
                self::debug('ajax_pump_exception', ['exception' => $e]);
            }
        }

        \wp_send_json_success(self::getUiStatusPayload());
    }

    /**
     * Processes the next scheduled workflow batch.
     */
    public static function tick(): void
    {
        self::registerFatalLogger('tick');

        $state = self::getState();
        if (($state['status'] ?? '') !== 'running') {
            self::debug('tick_skipped_not_running', ['status' => (string)($state['status'] ?? '')]);
            return;
        }

        $state['last_run_at'] = time();
        self::debug('tick_started', ['state' => self::summarizeState($state)]);

        $builder = sanitize_key((string)($state['builder'] ?? self::getConfiguredLayoutBuilder()));
        $builder = in_array($builder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $builder : 'elementor';
        $mode = sanitize_key((string)($state['mode'] ?? 'overwrite'));
        $mode = in_array($mode, ['overwrite', 'force'], true) ? $mode : 'overwrite';

        $limit  = (int)($state['limit'] ?? self::DEFAULT_BATCH);
        $offset = (int)($state['offset'] ?? 0);
        $limit  = max(1, min(self::MAX_BATCH, $limit));
        $offset = max(0, $offset);

        if ((int)($state['pages_total'] ?? 0) <= 0 || (int)($state['boats_total'] ?? 0) <= 0) {
            $targetStats = self::getTargetPagesStats();
            $state['boats_total'] = (int) ($targetStats['boats_total'] ?? 0);
            $state['pages_total'] = (int) ($targetStats['pages_total'] ?? 0);
            $state['languages_on_pages'] = (int) ($targetStats['languages_on_pages'] ?? 0);
            $state['language_codes'] = is_array($targetStats['language_codes'] ?? null) ? $targetStats['language_codes'] : [];
            self::debug('target_stats_loaded', ['target_stats' => $targetStats]);
        }

        $q = new \WP_Query([
            'post_type'      => \Maradigma\BoatPostType::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            // Stable paging: synced posts often share the same post_date.
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'lang'           => '',
        ]);

        $ids = is_array($q->posts) ? $q->posts : [];
        self::debug('query_batch_loaded', [
            'offset' => $offset,
            'limit' => $limit,
            'ids_count' => count($ids),
        ]);
        if (empty($ids)) {
            $state['status']  = 'done';
            $state['message'] = 'All boat pages processed.';
            self::storeState($state);
            self::unscheduleAllTicks();
            self::debug('tick_done_empty_batch', ['state' => self::summarizeState($state)]);
            return;
        }

        foreach ($ids as $id) {
            $boatPostId = (int)$id;
            if ($boatPostId <= 0) {
                continue;
            }

            $state['pages_scanned'] = (int) ($state['pages_scanned'] ?? 0) + 1;
            $state['boats_scanned'] = $state['pages_scanned'];

            try {
                if ($builder === 'gutenberg') {
                    if (!self::loadGutenbergIntegration()) {
                        self::incrementPageCounter($state, 'failed');
                        continue;
                    }

                    $applied = GutenbergIntegration::applyGutenbergTemplateToBoatPost($boatPostId, $mode);
                    if ($applied) {
                        self::incrementPageCounter($state, 'updated');
                    } else {
                        $reason = 'not_applied';
                        if (method_exists(GutenbergIntegration::class, 'getGutenbergTemplateApplySkipReason')) {
                            $reason = (string) GutenbergIntegration::getGutenbergTemplateApplySkipReason($boatPostId, $mode);
                            if ($reason === '') {
                                $reason = 'not_applied_unknown';
                            }
                        }

                        if ($reason === 'not_applied_unknown'
                            && method_exists(GutenbergIntegration::class, 'postMatchesGutenbergMasterTemplate')
                            && !GutenbergIntegration::postMatchesGutenbergMasterTemplate($boatPostId)
                        ) {
                            $reason = 'wp_update_post_did_not_persist_content';
                        }

                        $debug = [];
                        if ($reason === 'wp_update_post_did_not_persist_content'
                            && method_exists(GutenbergIntegration::class, 'getGutenbergTemplatePersistenceDebug')
                        ) {
                            $debug = GutenbergIntegration::getGutenbergTemplatePersistenceDebug($boatPostId);
                        }

                        self::recordSkippedBoat($state, $boatPostId, $reason, $debug);
                    }

                    continue;
                }

                if ($builder === 'wpbakery') {
                    if (!self::loadWPBakeryIntegration()) {
                        self::incrementPageCounter($state, 'failed');
                        continue;
                    }

                    $applied = WPBakeryIntegration::applyWPBakeryTemplateToBoatPost($boatPostId, $mode);
                    if ($applied) {
                        self::incrementPageCounter($state, 'updated');
                    } else {
                        $reason = WPBakeryIntegration::getWPBakeryTemplateApplySkipReason($boatPostId, $mode);
                        self::recordSkippedBoat($state, $boatPostId, $reason !== '' ? $reason : 'not_applied_unknown');
                    }

                    continue;
                }

                if ((bool)get_post_meta($boatPostId, self::META_ELEMENTOR_CUSTOM_LAYOUT, true) && $mode !== 'force') {
                    self::recordSkippedBoat($state, $boatPostId, 'custom_elementor_layout');
                    continue;
                }

                $masterId = (int)($state['master_id'] ?? 0);
                $p = $masterId > 0 ? get_post($masterId) : null;
                if (!$p || $p->post_type !== 'elementor_library') {
                    self::incrementPageCounter($state, 'failed');
                    continue;
                }

                $tplJson = (string) get_post_meta($masterId, '_elementor_data', true);
                if ($tplJson === '') {
                    self::incrementPageCounter($state, 'failed');
                    continue;
                }

                $masterHash = md5($tplJson);
                $state['master_hash'] = $masterHash;

                update_post_meta($boatPostId, '_elementor_data', wp_slash($tplJson));
                update_post_meta($boatPostId, '_elementor_edit_mode', 'builder');
                if (defined('ELEMENTOR_VERSION')) {
                    update_post_meta($boatPostId, '_elementor_version', ELEMENTOR_VERSION);
                }

                update_post_meta($boatPostId, self::META_LAYOUT_BUILDER, 'elementor');
                update_post_meta($boatPostId, self::META_ELEMENTOR_SEEDED, '1');

                $stored = (string) get_post_meta($boatPostId, '_elementor_data', true);
                update_post_meta($boatPostId, self::META_ELEMENTOR_DATA_HASH, md5($stored));
                update_post_meta($boatPostId, self::META_ELEMENTOR_TEMPLATE_HASH, $masterHash);

                self::regenerateElementorCssSafe($boatPostId);

                self::incrementPageCounter($state, 'updated');
            } catch (\Throwable $e) {
                self::incrementPageCounter($state, 'failed');
                self::debug('page_exception', [
                    'post_id' => $boatPostId,
                    'exception' => $e,
                ]);
            }
        }

        $state['offset'] = $offset + $limit;
        $state['message'] = sprintf('Processed %d boat pages (offset=%d)', count($ids), $state['offset']);

        self::storeState($state);
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 2, self::CRON_HOOK);
        }
        self::debug('tick_finished', ['state' => self::summarizeState($state)]);
    }

    /** @return array<string,mixed> */
    public static function getState(): array
    {
        $s = get_option(self::OPTION_STATE, []);
        return is_array($s) ? $s : [];
    }

    /**
     * Return a display-safe sync state with explicit page/boat semantics.
     *
     * Older plugin versions stored translated pages in `boats_total`. For the
     * admin UI we normalize that legacy state so `boats_total` always means
     * unique Maradigma boats, while `pages_total` means generated WP pages.
     *
     * @return array<string,mixed>
     */
    public static function getDisplayState(): array
    {
        return self::normalizeStateForDisplay(self::getState());
    }

    /**
     * @return array<string,mixed>
     */
    public static function getUiStatusPayload(): array
    {
        $state = self::getDisplayState();
        $nextCron = self::getNextScheduledAt();

        return [
            'state' => $state,
            'next_cron_at' => $nextCron,
            'next_cron_label' => $nextCron > 0
                ? \date_i18n(\get_option('date_format') . ' ' . \get_option('time_format'), $nextCron)
                : \__('Not scheduled', 'maradigma'),
            'last_run_label' => !empty($state['last_run_at'])
                ? \date_i18n(\get_option('date_format') . ' ' . \get_option('time_format'), (int) $state['last_run_at'])
                : \__('Never', 'maradigma'),
        ];
    }

    /**
     * Returns the timestamp of the next scheduled template synchronization batch.
     */
    public static function getNextScheduledAt(): int
    {
        $next = \wp_next_scheduled(self::CRON_HOOK);
        return $next ? (int) $next : 0;
    }

    /**
     * Validates the nonce and capabilities for a background AJAX request.
     */
    private static function checkAdminAjaxRequest(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_REQUEST['nonce'])
            ? \sanitize_text_field((string) \wp_unslash($_REQUEST['nonce']))
            : '';
        if (!\wp_verify_nonce($nonce, self::ADMIN_NONCE_ACTION)) {
            \wp_send_json_error(['message' => 'Invalid security token.'], 403);
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function recordSkippedBoat(array &$state, int $postId, string $reason, array $debug = []): void
    {
        $reason = sanitize_key($reason);
        if ($reason === '') {
            $reason = 'unknown';
        }

        self::incrementPageCounter($state, 'skipped');

        if (in_array($reason, ['custom_elementor_layout', 'custom_gutenberg_layout', 'custom_wpbakery_layout'], true)) {
            self::incrementPageCounter($state, 'skipped_custom');
        }

        $skipReasons = isset($state['skip_reasons']) && is_array($state['skip_reasons'])
            ? $state['skip_reasons']
            : [];
        $skipReasons[$reason] = (int) ($skipReasons[$reason] ?? 0) + 1;
        $state['skip_reasons'] = $skipReasons;

        $samples = isset($state['skipped_samples']) && is_array($state['skipped_samples'])
            ? $state['skipped_samples']
            : [];

        if (count($samples) < 10) {
            $sample = [
                'post_id' => $postId,
                'title'   => (string) get_the_title($postId),
                'reason'  => $reason,
            ];

            if ($debug !== []) {
                $sample['debug'] = $debug;
            }

            $samples[] = $sample;
            $state['skipped_samples'] = $samples;
        }
    }


    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function normalizeStateForDisplay(array $state): array
    {
        $needsTargetStats = (int) ($state['boats_total'] ?? 0) <= 0
            || (int) ($state['pages_total'] ?? 0) <= 0
            || !isset($state['languages_on_pages'])
            || !isset($state['language_codes'])
            || !is_array($state['language_codes']);

        if ($needsTargetStats) {
            $stats = self::getTargetPagesStats();

            if ((int) ($stats['boats_total'] ?? 0) > 0) {
                $state['boats_total'] = (int) $stats['boats_total'];
            }

            if ((int) ($stats['pages_total'] ?? 0) > 0) {
                $state['pages_total'] = (int) $stats['pages_total'];
            } elseif (!isset($state['pages_total']) && isset($state['boats_total'])) {
                $state['pages_total'] = (int) $state['boats_total'];
            }

            $state['languages_on_pages'] = (int) ($stats['languages_on_pages'] ?? 0);
            $state['language_codes'] = is_array($stats['language_codes'] ?? null) ? $stats['language_codes'] : [];
        }

        foreach (['scanned', 'updated', 'skipped_custom', 'skipped', 'failed'] as $counter) {
            $pageKey = 'pages_' . $counter;
            $legacyKey = 'boats_' . $counter;

            if (!isset($state[$pageKey]) && isset($state[$legacyKey])) {
                $state[$pageKey] = (int) $state[$legacyKey];
            }

            if (isset($state[$pageKey])) {
                $state[$legacyKey] = (int) $state[$pageKey];
            }
        }

        return $state;
    }

    /**
     * @return array{boats_total:int,pages_total:int,languages_on_pages:int,language_codes:array<int,string>}
     */
    private static function getTargetPagesStats(): array
    {
        $q = new \WP_Query([
            'post_type'      => \Maradigma\BoatPostType::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'lang'           => '',
        ]);

        $ids = is_array($q->posts) ? array_map('intval', $q->posts) : [];
        $boatIds = [];
        $languages = [];

        foreach ($ids as $postId) {
            if ($postId <= 0) {
                continue;
            }

            $boatId = trim((string) get_post_meta($postId, '_maradigma_boat_id', true));
            if ($boatId !== '') {
                $boatIds[$boatId] = true;
            }

            $language = '';
            if (function_exists('pll_get_post_language')) {
                $language = strtolower(trim((string) pll_get_post_language($postId)));
            } elseif (defined('ICL_SITEPRESS_VERSION') && has_filter('wpml_post_language_details')) {
                $details = apply_filters('wpml_post_language_details', null, $postId);
                if (is_array($details) && isset($details['language_code'])) {
                    $language = strtolower(trim((string) $details['language_code']));
                }
            }

            if ($language !== '') {
                $languages[$language] = true;
            }
        }

        $languageCodes = array_keys($languages);
        sort($languageCodes, SORT_STRING);

        return [
            'boats_total' => count($boatIds) > 0 ? count($boatIds) : count($ids),
            'pages_total' => count($ids),
            'languages_on_pages' => count($languageCodes),
            'language_codes' => $languageCodes,
        ];
    }

    /** @param array<string,mixed> $state */
    private static function incrementPageCounter(array &$state, string $counter): void
    {
        $pageKey = 'pages_' . $counter;
        $legacyKey = 'boats_' . $counter;

        $state[$pageKey] = (int) ($state[$pageKey] ?? 0) + 1;
        $state[$legacyKey] = $state[$pageKey];
    }

    /**
     * Removes all pending cron events for the template synchronization workflow.
     */
    private static function unscheduleAllTicks(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    /** @param array<string,mixed> $state */
    private static function storeState(array $state): void
    {
        update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Returns configured layout builder.
     */
    private static function getConfiguredLayoutBuilder(): string
    {
        $settings = \Maradigma\SettingsPage::getSettings();
        $builder = sanitize_key((string)($settings['boat_layout_builder'] ?? 'elementor'));

        return in_array($builder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $builder : 'elementor';
    }

    /**
     * Loads Gutenberg integration.
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
     * Loads WPBakery integration.
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
     * Regenerates Elementor CSS when the integration is available.
     */
    private static function regenerateElementorCssSafe(int $postId): void
    {
        try {
            if (!class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
                return;
            }
            \Elementor\Core\Files\CSS\Post::create($postId)->update();
        } catch (\Throwable) {
            // no-op
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function summarizeState(array $state): array
    {
        return [
            'status' => (string)($state['status'] ?? ''),
            'builder' => (string)($state['builder'] ?? ''),
            'mode' => (string)($state['mode'] ?? ''),
            'started_at' => (int)($state['started_at'] ?? 0),
            'last_run_at' => (int)($state['last_run_at'] ?? 0),
            'offset' => (int)($state['offset'] ?? 0),
            'limit' => (int)($state['limit'] ?? 0),
            'boats_total' => (int)($state['boats_total'] ?? 0),
            'pages_total' => (int)($state['pages_total'] ?? 0),
            'pages_scanned' => (int)($state['pages_scanned'] ?? 0),
            'pages_updated' => (int)($state['pages_updated'] ?? 0),
            'pages_failed' => (int)($state['pages_failed'] ?? 0),
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
     * Registers a shutdown handler that records fatal template synchronization errors.
     */
    private static function registerFatalLogger(string $stage): void
    {
        static $registered = [];
        if (isset($registered[$stage])) {
            return;
        }

        $registered[$stage] = true;

        register_shutdown_function(static function () use ($stage): void {
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
            if ((string)($state['status'] ?? '') === 'running') {
                $state['status'] = 'error';
                $state['message'] = 'Fatal error during template sync: ' . (string)($error['message'] ?? '');
                self::storeState($state);
            }

            self::debug('shutdown_fatal', [
                'stage' => $stage,
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
}
