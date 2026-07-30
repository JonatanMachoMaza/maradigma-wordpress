<?php

declare(strict_types=1);

namespace Maradigma;

if (!defined('ABSPATH')) {
    exit;
}

use Maradigma\Support\Debugger;

/**
 * Boat Images Sync (Media Library cache) - independent of CPT pages.
 *
 * Goals:
 * - Store boat images locally in WP Media Library when setting is enabled.
 * - Work even if the customer does NOT enable boat pages sync.
 * - Avoid timeouts by batching (WP-Cron tick).
 * - Provide progress/stats and support re-running to pick new/changed images.
 *
 * Dedup:
 * - Do NOT dedup only by remote URL, because the same remote URL can be reused
 *   across different boats.
 * - Dedup by (remote_url + boat_id).
 *
 * Ordering:
 * - API provides "number_order" starting at 1.
 * - We persist:
 *   - _maradigma_image_order (int)
 *   - _maradigma_is_cover (int)
 *
 * IMPORTANT:
 * - We NEVER use API url_sizes for rendering.
 * - We download ONLY ONE main URL per image, then WordPress generates ONLY our custom sizes.
 */
final class BoatImagesSyncService
{
    private const OPTION_STATE = 'maradigma_boat_images_sync_state';
    private const LOCK_TRANSIENT = 'maradigma_boat_images_sync_lock';

    private const META_ATTACHMENT_REMOTE_URL  = '_maradigma_remote_url';
    private const META_ATTACHMENT_BOAT_ID     = '_maradigma_boat_id';
    private const META_ATTACHMENT_IMAGE_ORDER = '_maradigma_image_order';
    private const META_ATTACHMENT_IS_COVER    = '_maradigma_is_cover';

    private const DEBUG_CHANNEL = 'boat-images-sync';
    private const CRON_HOOK     = 'maradigma_boat_images_sync_tick';
    private const AJAX_ACTION   = 'maradigma_boat_images_sync_kick';
    private const AJAX_STATUS_ACTION = 'maradigma_boat_images_sync_status';
    private const AJAX_PUMP_ACTION   = 'maradigma_boat_images_sync_pump';

    private const BATCH_BOATS = 1;
    private const MAX_CONFIGURABLE_IMAGES_PER_BOAT = 25;
    private const SOFT_TIME_BUDGET_SECONDS = 20;
    private const LOCK_TTL_SECONDS = 90;
    private const DOWNLOAD_TIMEOUT_SECONDS = 20;

    /**
     * @var array<string,array{w:int,h:int,crop:bool}>
     */
    private const MARADIGMA_IMAGE_SIZES = [
        'maradigma_thumb_150x150'      => ['w' => 150,  'h' => 150,  'crop' => true],
        'maradigma_thumb_300x300'      => ['w' => 300,  'h' => 300,  'crop' => true],

        'maradigma_card_471x273'       => ['w' => 471,  'h' => 273,  'crop' => true],
        'maradigma_card_640x360'       => ['w' => 640,  'h' => 360,  'crop' => true],
        'maradigma_card_960x540'       => ['w' => 960,  'h' => 540,  'crop' => true],

        'maradigma_land_800x600'       => ['w' => 800,  'h' => 600,  'crop' => true],
        'maradigma_land_1200x900'      => ['w' => 1200, 'h' => 900,  'crop' => true],

        'maradigma_hero_1200x500'      => ['w' => 1200, 'h' => 500,  'crop' => true],
        'maradigma_hero_1600x600'      => ['w' => 1600, 'h' => 600,  'crop' => true],

        'maradigma_lightbox_1600x900'  => ['w' => 1600, 'h' => 900,  'crop' => true],
        'maradigma_lightbox_1920x1080' => ['w' => 1920, 'h' => 1080, 'crop' => true],

        'maradigma_max_1200'           => ['w' => 1200, 'h' => 0,    'crop' => false],
        'maradigma_max_1600'           => ['w' => 1600, 'h' => 0,    'crop' => false],
    ];

    public static function init(): void
    {
        \add_action(self::CRON_HOOK, [self::class, 'tick']);

        \add_action('wp_ajax_' . self::AJAX_ACTION, [self::class, 'handleAsyncKick']);
        \add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, [self::class, 'handleAsyncKick']);
        \add_action('wp_ajax_' . self::AJAX_STATUS_ACTION, [self::class, 'handleAdminStatus']);
        \add_action('wp_ajax_' . self::AJAX_PUMP_ACTION, [self::class, 'handleAdminPump']);

        \add_action('init', [self::class, 'registerImageSizes'], 20);
    }

    public static function registerImageSizes(): void
    {
        if (!\function_exists('add_image_size')) {
            self::debug('register_image_sizes_skipped', [
                'reason' => 'add_image_size_not_available',
            ]);
            return;
        }

        foreach (self::MARADIGMA_IMAGE_SIZES as $name => $cfg) {
            $w = (int) ($cfg['w'] ?? 0);
            $h = (int) ($cfg['h'] ?? 0);
            $c = !empty($cfg['crop']);

            if ($w <= 0) {
                continue;
            }

            if ($h <= 0) {
                \add_image_size($name, $w, 9999, false);
                continue;
            }

            \add_image_size($name, $w, $h, $c);
        }

        self::debug('register_image_sizes_done', [
            'sizes_count' => count(self::MARADIGMA_IMAGE_SIZES),
        ]);
    }

    public static function start(bool $forceResync = false): void
    {
        $state = self::buildFreshState($forceResync);
        self::saveState($state, true);

        self::debug('start', [
            'force_resync' => $forceResync ? 1 : 0,
            'state'        => $state,
        ]);

        self::scheduleNextTick(2);
    }

    public static function stop(): void
    {
        $state = self::getState();
        $state['status']  = 'stopped';
        $state['message'] = 'Stopped by user';
        $state['worker_state'] = 'stopped';
        $state['async_key'] = '';

        self::saveState($state, true);
        self::unscheduleAllTicks();

        self::debug('stop', [
            'state' => $state,
        ]);
    }

    public static function handleAsyncKick(): void
    {
        if (\function_exists('ignore_user_abort')) {
            @\ignore_user_abort(true);
        }

        $state = self::getState();
        // The per-run random async key is the authentication token for this internal callback.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $providedKey = isset($_REQUEST['async_key'])
            ? \sanitize_text_field((string) \wp_unslash($_REQUEST['async_key']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $expectedKey = (string) ($state['async_key'] ?? '');

        if (
            $expectedKey === ''
            || $providedKey === ''
            || !\hash_equals($expectedKey, $providedKey)
        ) {
            self::debug('async_kick_rejected_invalid_key', [
                'has_expected_key' => $expectedKey !== '' ? 1 : 0,
                'has_provided_key' => $providedKey !== '' ? 1 : 0,
            ]);

            if (\function_exists('status_header')) {
                \status_header(403);
            }
            exit;
        }

        if (($state['status'] ?? '') !== 'running') {
            self::debug('async_kick_skipped_not_running', [
                'state' => $state,
            ]);

            if (\function_exists('status_header')) {
                \status_header(204);
            }
            exit;
        }

        self::debug('async_kick_started', [
            'offset' => (int) ($state['offset'] ?? 0),
        ]);

        self::tick();

        if (\function_exists('status_header')) {
            \status_header(204);
        }
        exit;
    }

    public static function handleAdminStatus(): void
    {
        self::checkAdminAjaxRequest();
        \wp_send_json_success(self::getUiStatusPayload());
    }

    public static function handleAdminPump(): void
    {
        self::checkAdminAjaxRequest();

        $state = self::getState();
        if (($state['status'] ?? '') === 'running' && !self::isLockActive()) {
            self::tick();
        } elseif (($state['status'] ?? '') === 'running') {
            self::scheduleNextTick(5, false);
        }

        \wp_send_json_success(self::getUiStatusPayload());
    }

    public static function tick(): void
    {
        self::registerShutdownHandler();

        $settings = SettingsPage::getSettings();

        if (empty($settings['store_boat_images_locally'])) {
            $state = self::getState();
            $state['status']  = 'stopped';
            $state['message'] = 'Stopped: setting disabled';
            self::saveState($state);

            self::debug('tick_stopped_setting_disabled', [
                'state' => $state,
            ]);

            return;
        }

        $state = self::normalizeState(self::getState());

        if (($state['status'] ?? '') !== 'running') {
            self::debug('tick_skipped_not_running', [
                'state' => $state,
            ]);
            return;
        }

        $lockToken = self::acquireLock();
        if ($lockToken === '') {
            $state['message'] = 'Waiting for another image sync worker to finish';
            if ((string) ($state['worker_state'] ?? '') !== 'processing') {
                $state['worker_state'] = 'waiting_lock';
            }
            self::saveState($state);
            self::scheduleNextTick(5, false);

            self::debug('tick_skipped_locked', [
                'state' => $state,
            ]);
            return;
        }

        try {
            $tickStartedAt = \microtime(true);

        $state['last_run_at'] = \time();
        $state['worker_state'] = 'processing';
        self::saveState($state);

        $limit  = (int) ($state['limit'] ?? self::BATCH_BOATS);
        $offset = (int) ($state['offset'] ?? 0);

        if ($limit < 1) {
            $limit = self::BATCH_BOATS;
        }

        if ($offset < 0) {
            $offset = 0;
        }

        self::debug('tick_started', [
            'offset' => $offset,
            'limit'  => $limit,
            'state'  => $state,
        ]);

            if (!self::hasActiveBoatCheckpoint($state)) {
                $cache  = new Cache();
                $result = $cache->getBoatsList([
                    'id_group'        => 'boats',
                    'limit_services'  => $limit,
                    'offset_services' => $offset,
                ]);

                $boats = [];
                $total = 0;

                if (!empty($result['success']) && \is_array($result['data'] ?? null)) {
                    $boats = \is_array($result['data']['search_result'] ?? null) ? $result['data']['search_result'] : [];
                    $total = isset($result['data']['total_results']) ? (int) $result['data']['total_results'] : 0;
                }

                self::debug('boats_list_fetched', [
                    'success'       => !empty($result['success']) ? 1 : 0,
                    'offset'        => $offset,
                    'limit'         => $limit,
                    'boats_count'   => \is_array($boats) ? count($boats) : 0,
                    'total_results' => $total,
                    'result_keys'   => \is_array($result) ? array_keys($result) : [],
                ]);

                if ((int) ($state['boats_total'] ?? 0) <= 0 && $total > 0) {
                    $state['boats_total'] = $total;
                    self::saveState($state);
                }

                if (empty($boats)) {
                    $state['status'] = 'done';

                    $boatsTotal     = (int) ($state['boats_total'] ?? 0);
                    $boatsProcessed = (int) ($state['boats_processed'] ?? 0);

                    $state['message'] = ($boatsTotal > 0 && $boatsProcessed >= $boatsTotal)
                        ? 'All boats processed successfully'
                        : 'No more boats to process';

                    self::saveState($state);

                    self::debug('tick_done', [
                        'boats_processed'     => $state['boats_processed'],
                        'boats_total'         => $state['boats_total'],
                        'images_found'        => $state['images_found'],
                        'images_imported'     => $state['images_imported'],
                        'images_reused'       => $state['images_reused'],
                        'images_failed'       => $state['images_failed'],
                        'images_updated_meta' => $state['images_updated_meta'],
                        'cache_stats'         => self::getCacheStats(),
                        'state'               => $state,
                    ]);

                    return;
                }

                $boat = $boats[0] ?? null;

                if (!\is_array($boat)) {
                    $state['message'] = 'Invalid boat payload';
                    $state['offset']  = $offset + 1;
                    self::saveState($state);

                    self::debug('boat_skipped_not_array', [
                        'boat_type' => get_debug_type($boat),
                    ]);

                    self::scheduleNextTick(2);
                    return;
                }

                $boatId = (string) ($boat['id'] ?? $boat['id_gi'] ?? $boat['id_group_item'] ?? '');
                $boatId = \trim($boatId);

                if ($boatId === '') {
                    $state['message'] = 'Boat skipped: missing id';
                    $state['offset']  = $offset + 1;
                    self::saveState($state);

                    self::debug('boat_skipped_missing_id', [
                        'boat_keys' => array_keys($boat),
                    ]);

                    self::scheduleNextTick(2);
                    return;
                }

                self::debug('boat_processing_started', [
                    'boat_id'   => $boatId,
                    'boat_keys' => array_keys($boat),
                ]);

                $boatFull = $boat;

                try {
                    $imagesResponse = $cache->getServiceImages('boats', $boatId, 'EN', true);

                    self::debug('boat_images_response', [
                        'boat_id'          => $boatId,
                        'status'           => (string) ($imagesResponse['status'] ?? ''),
                        'has_data'         => \is_array($imagesResponse['data'] ?? null) ? 1 : 0,
                        'images_raw_count' => \is_array($imagesResponse['data'] ?? null) ? count($imagesResponse['data']) : 0,
                        'response_keys'    => \is_array($imagesResponse) ? array_keys($imagesResponse) : [],
                    ]);

                    if (
                        \is_array($imagesResponse)
                        && ($imagesResponse['status'] ?? '') === 'success'
                        && \is_array($imagesResponse['data'] ?? null)
                    ) {
                        $boatFull = [
                            'id'     => $boatId,
                            'images' => $imagesResponse['data'],
                        ];
                    }
                } catch (\Throwable $e) {
                    self::debug('boat_images_response_exception', [
                        'boat_id'   => $boatId,
                        'exception' => [
                            'type'    => $e::class,
                            'message' => $e->getMessage(),
                            'file'    => $e->getFile(),
                            'line'    => $e->getLine(),
                        ],
                    ]);
                }

                $images = self::extractBoatImages($boatFull);
                self::pruneStaleAttachmentsForBoat($boatId, $images);

                self::debug('boat_images_extracted', [
                    'boat_id'        => $boatId,
                    'images_count'   => count($images),
                    'images_sample'  => array_slice($images, 0, 3),
                    'boat_full_keys' => array_keys($boatFull),
                ]);

                $state['current_boat_id']           = $boatId;
                $state['current_boat_offset']       = $offset;
                $state['current_boat_images']       = $images;
                $state['current_boat_images_count'] = count($images);
                $state['current_image_index']       = 0;
                $state['message']                   = 'Processing boat ' . $boatId;

                if (!empty($images)) {
                    $state['images_found'] += count($images);
                } else {
                    self::debug('boat_has_no_valid_images', [
                        'boat_id' => $boatId,
                    ]);
                }

                self::saveState($state);
            }

            $state = self::normalizeState(self::getState());

            $boatId            = (string) ($state['current_boat_id'] ?? '');
            $currentBoatOffset = (int) ($state['current_boat_offset'] ?? $offset);
            $images            = \is_array($state['current_boat_images'] ?? null) ? $state['current_boat_images'] : [];
            $currentImageIndex = (int) ($state['current_image_index'] ?? 0);
            $imagesCount       = (int) ($state['current_boat_images_count'] ?? count($images));

            if ($boatId === '') {
                $state['status']  = 'error';
                $state['message'] = 'Invalid checkpoint state: missing current_boat_id';
                self::saveState($state);

                self::debug('tick_invalid_checkpoint_missing_boat_id', [
                    'state' => $state,
                ]);

                return;
            }

            $anyOrder = false;
            foreach ($images as $row) {
                if (!empty($row['number_order'])) {
                    $anyOrder = true;
                    break;
                }
            }

            for ($i = $currentImageIndex; $i < $imagesCount; $i++) {
                if (self::isStopRequested()) {
                    self::debug('tick_cancelled_before_image', [
                        'boat_id'     => $boatId,
                        'image_index' => $i,
                    ]);
                    return;
                }

                $row = $images[$i] ?? null;

                if (!\is_array($row)) {
                    $state['images_failed']++;
                    $state['current_image_index'] = $i + 1;
                    $state['message'] = 'Skipped invalid image row in boat ' . $boatId;
                    self::saveState($state);

                    self::debug('image_skipped_invalid_row', [
                        'boat_id'     => $boatId,
                        'image_index' => $i,
                        'row_type'    => get_debug_type($row),
                    ]);

                    continue;
                }

                $remoteUrl   = (string) ($row['url'] ?? '');
                $numberOrder = (int) ($row['number_order'] ?? 0);

                if ($remoteUrl === '') {
                    $state['images_failed']++;
                    $state['current_image_index'] = $i + 1;
                    $state['message'] = 'Skipped empty image url in boat ' . $boatId;
                    self::saveState($state);

                    self::debug('image_skipped_empty_url', [
                        'boat_id'      => $boatId,
                        'number_order' => $numberOrder,
                        'image_index'  => $i,
                        'row'          => $row,
                    ]);

                    continue;
                }

                if ($numberOrder < 0) {
                    $numberOrder = 0;
                }

                if (!$anyOrder && $i === 0) {
                    $numberOrder = 1;
                }

                $force = !empty($state['force_resync']);

                self::debug('image_processing_started', [
                    'boat_id'      => $boatId,
                    'remote_url'   => $remoteUrl,
                    'number_order' => $numberOrder,
                    'image_index'  => $i,
                    'force'        => $force ? 1 : 0,
                ]);

                $res = self::ensureAttachmentForRemoteUrl(
                    $remoteUrl,
                    $boatId,
                    $force,
                    $numberOrder
                );

                if (self::isStopRequested()) {
                    self::debug('tick_cancelled_after_image', [
                        'boat_id'     => $boatId,
                        'image_index' => $i,
                        'result'      => $res,
                    ]);
                    return;
                }

                if ($res === 'imported') {
                    $state['images_imported']++;
                } elseif ($res === 'reused') {
                    $state['images_reused']++;
                } elseif ($res === 'meta_updated') {
                    $state['images_updated_meta']++;
                } else {
                    $state['images_failed']++;
                }

                $state['current_image_index'] = $i + 1;
                $state['message'] = 'Processing boat ' . $boatId . ' image ' . ($i + 1) . '/' . $imagesCount;
                self::saveState($state);

                self::debug('image_processing_finished', [
                    'boat_id'      => $boatId,
                    'remote_url'   => $remoteUrl,
                    'number_order' => $numberOrder,
                    'image_index'  => $i,
                    'result'       => $res,
                ]);

                $isLastImageOfCurrentBoat = (($i + 1) >= $imagesCount);

                if (
                    !$isLastImageOfCurrentBoat
                    && (\microtime(true) - $tickStartedAt) >= self::SOFT_TIME_BUDGET_SECONDS
                ) {
                    $state['message'] = 'Tick paused by soft time budget during image processing';
                    self::saveState($state);

                    self::scheduleNextTick(2);

                    self::debug('tick_paused_soft_budget_during_images', [
                        'boat_id'              => $boatId,
                        'current_image_index'  => $state['current_image_index'],
                        'current_images_count' => $state['current_boat_images_count'],
                        'boats_processed'      => $state['boats_processed'],
                        'boats_total'          => $state['boats_total'],
                        'images_found'         => $state['images_found'],
                        'images_imported'      => $state['images_imported'],
                        'images_reused'        => $state['images_reused'],
                        'images_failed'        => $state['images_failed'],
                        'images_updated_meta'  => $state['images_updated_meta'],
                    ]);

                    return;
                }
            }

            $state['boats_processed']++;
            $state['offset']  = $currentBoatOffset + 1;
            $state['message'] = 'Processed boat ' . $boatId;

            self::clearCurrentBoatCheckpoint($state);
            self::saveState($state);

            self::debug('boat_processing_finished', [
                'boat_id'             => $boatId,
                'boats_processed'     => $state['boats_processed'],
                'boats_total'         => $state['boats_total'],
                'offset'              => $state['offset'],
                'images_found'        => $state['images_found'],
                'images_imported'     => $state['images_imported'],
                'images_reused'       => $state['images_reused'],
                'images_failed'       => $state['images_failed'],
                'images_updated_meta' => $state['images_updated_meta'],
            ]);

            $boatsTotal     = (int) ($state['boats_total'] ?? 0);
            $boatsProcessed = (int) ($state['boats_processed'] ?? 0);

            if (self::isStopRequested()) {
                self::debug('tick_cancelled_after_boat', [
                    'boat_id' => $boatId,
                ]);
                return;
            }

            if ($boatsTotal > 0 && $boatsProcessed >= $boatsTotal) {
                $state['status']  = 'done';
                $state['message'] = 'All boats processed successfully';
                self::saveState($state);

                self::debug('tick_done_all_processed', [
                    'boats_processed'     => $state['boats_processed'],
                    'boats_total'         => $state['boats_total'],
                    'images_found'        => $state['images_found'],
                    'images_imported'     => $state['images_imported'],
                    'images_reused'       => $state['images_reused'],
                    'images_failed'       => $state['images_failed'],
                    'images_updated_meta' => $state['images_updated_meta'],
                    'cache_stats'         => self::getCacheStats(),
                    'state'               => $state,
                ]);

                return;
            }

            self::scheduleNextTick(2);

            self::debug('tick_finished', [
                'next_offset'         => $state['offset'],
                'boats_processed'     => $state['boats_processed'],
                'boats_total'         => $state['boats_total'],
                'images_found'        => $state['images_found'],
                'images_imported'     => $state['images_imported'],
                'images_reused'       => $state['images_reused'],
                'images_failed'       => $state['images_failed'],
                'images_updated_meta' => $state['images_updated_meta'],
                'cache_stats'         => self::getCacheStats(),
            ]);
        } catch (\Throwable $e) {
            $state['status']  = 'error';
            $state['message'] = $e->getMessage();
            $state['worker_state'] = 'error';
            self::saveState($state);

            self::debug('tick_fatal_error', [
                'state' => $state,
                'exception' => [
                    'type'    => $e::class,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ],
            ]);
        } finally {
            self::releaseLock($lockToken);
        }
    }

    /**
     * @return array{deleted:int,errors:int}
     */
    public static function resetCache(bool $deleteAttachments = true): array
    {
        $previousState = self::getState();

        if (!empty($previousState)) {
            $previousState['status']  = 'stopped';
            $previousState['message'] = 'Reset requested';
            $previousState['worker_state'] = 'stopped';
            $previousState['async_key'] = '';
            self::saveState($previousState, true);
        }

        self::debug('reset_cache_started', [
            'delete_attachments' => $deleteAttachments ? 1 : 0,
            'previous_state'     => $previousState,
        ]);

        self::unscheduleAllTicks();
        \delete_option(self::OPTION_STATE);

        if (!$deleteAttachments) {
            self::debug('reset_cache_finished_no_delete', [
                'deleted' => 0,
                'errors'  => 0,
            ]);

            return ['deleted' => 0, 'errors' => 0];
        }

        if (!\function_exists('wp_delete_attachment')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }

        $q = new \WP_Query([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'lang'             => '',
            'meta_query'       => [
                'relation' => 'OR',
                [
                    'key'     => self::META_ATTACHMENT_REMOTE_URL,
                    'compare' => 'EXISTS',
                ],
                [
                    'key'     => self::META_ATTACHMENT_BOAT_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $deleted = 0;
        $errors  = 0;

        if (!empty($q->posts) && \is_array($q->posts)) {
            foreach ($q->posts as $attId) {
                $attId = (int) $attId;
                if ($attId <= 0) {
                    continue;
                }

                $ok = \wp_delete_attachment($attId, true);
                if ($ok) {
                    $deleted++;
                } else {
                    $errors++;
                }
            }
        }

        self::debug('reset_cache_finished', [
            'attachments_found' => \is_array($q->posts ?? null) ? count($q->posts) : 0,
            'deleted'           => $deleted,
            'errors'            => $errors,
        ]);

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * @return array<string,mixed>
     */
    public static function getState(): array
    {
        $s = \get_option(self::OPTION_STATE, []);
        return self::normalizeState(\is_array($s) ? $s : []);
    }

    /**
     * @return array<string,int>
     */
    public static function getCacheStats(): array
    {
        global $wpdb;

        $metaBoatId = self::META_ATTACHMENT_BOAT_ID;

        $attachments = (int) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                WHERE p.post_type = %s
                  AND p.post_status = %s
                  AND pm.meta_key = %s
                  AND pm.meta_value <> ''
                ",
                'attachment',
                'inherit',
                $metaBoatId
            )
        );

        $boats = (int) $wpdb->get_var(
            $wpdb->prepare(
                "
                SELECT COUNT(DISTINCT pm.meta_value)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                WHERE p.post_type = %s
                  AND p.post_status = %s
                  AND pm.meta_key = %s
                  AND pm.meta_value <> ''
                ",
                'attachment',
                'inherit',
                $metaBoatId
            )
        );

        return [
            'attachments' => max(0, $attachments),
            'boats'       => max(0, $boats),
        ];
    }

    /**
     * @return array<int,array{url:string,number_order:int}>
     */
    private static function extractBoatImages(array $boatFull): array
    {
        self::debug('extract_images_started', [
            'has_images_key'   => array_key_exists('images', $boatFull) ? 1 : 0,
            'images_is_array'  => \is_array($boatFull['images'] ?? null) ? 1 : 0,
            'images_raw_count' => \is_array($boatFull['images'] ?? null) ? count($boatFull['images']) : 0,
            'boat_keys'        => array_keys($boatFull),
        ]);

        $out = [];

        if (!isset($boatFull['images']) || !\is_array($boatFull['images'])) {
            self::debug('extract_images_skipped_invalid_images_key', [
                'boat_keys' => array_keys($boatFull),
            ]);
            return [];
        }

        foreach ($boatFull['images'] as $img) {
            if (!\is_array($img)) {
                continue;
            }

            $numberOrder = isset($img['number_order']) && \is_numeric($img['number_order'])
                ? (int) $img['number_order']
                : 0;

            $u = '';
            if (!empty($img['url']) && \is_string($img['url'])) {
                $u = \trim((string) $img['url']);
            }

            if ($u === '') {
                continue;
            }

            $out[] = [
                'url'          => $u,
                'number_order' => $numberOrder,
            ];

            $maxImages = self::getMaxImagesPerBoat();
            if ($maxImages > 0 && count($out) >= $maxImages) {
                break;
            }
        }

        $byUrl = [];
        foreach ($out as $row) {
            $url = $row['url'];
            $ord = (int) $row['number_order'];

            if (!isset($byUrl[$url])) {
                $byUrl[$url] = $row;
                continue;
            }

            $prev = (int) $byUrl[$url]['number_order'];
            if ($prev === 0 || ($ord > 0 && $ord < $prev)) {
                $byUrl[$url] = $row;
            }
        }

        $out = array_values($byUrl);

        \usort($out, static function (array $a, array $b): int {
            $oa = (int) ($a['number_order'] ?? 0);
            $ob = (int) ($b['number_order'] ?? 0);

            if ($oa === 0 && $ob === 0) {
                return 0;
            }

            if ($oa === 0) {
                return 1;
            }

            if ($ob === 0) {
                return -1;
            }

            return $oa <=> $ob;
        });

        self::debug('extract_images_finished', [
            'normalized_count'  => count($out),
            'normalized_sample' => array_slice($out, 0, 3),
        ]);

        return $out;
    }

    private static function getMaxImagesPerBoat(): int
    {
        $settings = \Maradigma\SettingsPage::getSettings();
        $max = isset($settings['max_images_per_boat']) ? (int) $settings['max_images_per_boat'] : 0;
        if ($max <= 0) {
            return 0;
        }

        return min(self::MAX_CONFIGURABLE_IMAGES_PER_BOAT, $max);
    }

    /**
     * @return 'imported'|'reused'|'meta_updated'|'failed'
     */
    private static function ensureAttachmentForRemoteUrl(string $remoteUrl, string $boatId, bool $forceResync, int $numberOrder): string
    {
        $remoteUrl = \trim($remoteUrl);
        $boatId    = \trim($boatId);

        if ($remoteUrl === '' || $boatId === '') {
            self::debug('ensure_attachment_failed_invalid_input', [
                'boat_id'      => $boatId,
                'remote_url'   => $remoteUrl,
                'number_order' => $numberOrder,
            ]);
            return 'failed';
        }

        self::cleanupDuplicateAttachmentsByRemoteUrlAndBoatId($remoteUrl, $boatId);

        $attId = self::findAttachmentByRemoteUrlAndBoatId($remoteUrl, $boatId);

        if ($attId > 0) {
            if ($forceResync) {
                $deleted = \wp_delete_attachment($attId, true);

                if (!$deleted) {
                    self::debug('ensure_attachment_force_delete_failed', [
                        'boat_id'       => $boatId,
                        'remote_url'    => $remoteUrl,
                        'attachment_id' => $attId,
                    ]);
                    return 'failed';
                }

                $attId = 0;
            } else {
                $changed = self::ensureAttachmentMeta($attId, $boatId, $numberOrder);

                self::debug('ensure_attachment_reused_existing', [
                    'boat_id'       => $boatId,
                    'remote_url'    => $remoteUrl,
                    'attachment_id' => $attId,
                    'meta_changed'  => $changed ? 1 : 0,
                ]);

                return $changed ? 'meta_updated' : 'reused';
            }
        }

        $newId = self::importAttachmentFromUrl($remoteUrl, $boatId);
        if ($newId <= 0) {
            self::debug('ensure_attachment_failed_import', [
                'boat_id'    => $boatId,
                'remote_url' => $remoteUrl,
            ]);
            return 'failed';
        }

        self::ensureAttachmentMeta($newId, $boatId, $numberOrder);

        self::debug('ensure_attachment_imported', [
            'boat_id'       => $boatId,
            'remote_url'    => $remoteUrl,
            'attachment_id' => $newId,
            'force_resync'  => $forceResync ? 1 : 0,
        ]);

        return 'imported';
    }

    private static function ensureAttachmentMeta(int $attId, string $boatId, int $numberOrder): bool
    {
        $changed = false;

        $prevBoat = (string) \get_post_meta($attId, self::META_ATTACHMENT_BOAT_ID, true);
        if ($prevBoat !== $boatId) {
            \update_post_meta($attId, self::META_ATTACHMENT_BOAT_ID, $boatId);
            $changed = true;
        }

        $prevOrderRaw = \get_post_meta($attId, self::META_ATTACHMENT_IMAGE_ORDER, true);
        $prevOrder    = \is_numeric($prevOrderRaw) ? (int) $prevOrderRaw : 0;

        if ($numberOrder > 0 && $prevOrder !== $numberOrder) {
            \update_post_meta($attId, self::META_ATTACHMENT_IMAGE_ORDER, $numberOrder);
            $changed = true;
        } elseif ($numberOrder <= 0 && $prevOrderRaw === '') {
            \update_post_meta($attId, self::META_ATTACHMENT_IMAGE_ORDER, 0);
            $changed = true;
        }

        $isCover      = ($numberOrder === 1) ? 1 : 0;
        $prevCoverRaw = \get_post_meta($attId, self::META_ATTACHMENT_IS_COVER, true);
        $prevCover    = \is_numeric($prevCoverRaw) ? (int) $prevCoverRaw : 0;

        if ($prevCover !== $isCover) {
            \update_post_meta($attId, self::META_ATTACHMENT_IS_COVER, $isCover);
            $changed = true;
        }

        self::debug('ensure_attachment_meta', [
            'attachment_id' => $attId,
            'boat_id'       => $boatId,
            'number_order'  => $numberOrder,
            'changed'       => $changed ? 1 : 0,
            'is_cover'      => $isCover,
        ]);

        return $changed;
    }

    /**
     * @return array<string,array{width:int,height:int,crop:bool}>
     */
    private static function buildIntermediateSizesMap(): array
    {
        $out = [];

        foreach (self::MARADIGMA_IMAGE_SIZES as $name => $cfg) {
            $w = (int) ($cfg['w'] ?? 0);
            $h = (int) ($cfg['h'] ?? 0);
            $c = !empty($cfg['crop']);

            if ($w <= 0) {
                continue;
            }

            if ($h <= 0) {
                $out[$name] = ['width' => $w, 'height' => 9999, 'crop' => false];
                continue;
            }

            $out[$name] = ['width' => $w, 'height' => $h, 'crop' => $c];
        }

        return $out;
    }

    private static function importAttachmentFromUrl(string $remoteUrl, string $boatId): int
    {
        self::debug('import_attachment_started', [
            'boat_id'    => $boatId,
            'remote_url' => $remoteUrl,
        ]);

        if (!\function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!\function_exists('wp_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!\function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp = \download_url($remoteUrl, self::DOWNLOAD_TIMEOUT_SECONDS);
        if (\is_wp_error($tmp)) {
            self::debug('import_attachment_download_failed', [
                'boat_id'    => $boatId,
                'remote_url' => $remoteUrl,
                'error'      => $tmp->get_error_message(),
            ]);
            return 0;
        }

        $name = \basename((string) \wp_parse_url($remoteUrl, PHP_URL_PATH));
        $name = \trim($name);

        if ($name === '' || $name === '/' || \strpos($name, '.') === false) {
            $name = 'maradigma-' . \md5($remoteUrl . '|' . $boatId) . '.jpg';
        }

        $file     = ['name' => $name, 'tmp_name' => $tmp];
        $sideload = \wp_handle_sideload($file, ['test_form' => false]);

        if (!empty($sideload['error'])) {
            self::debug('import_attachment_sideload_failed', [
                'boat_id'    => $boatId,
                'remote_url' => $remoteUrl,
                'error'      => (string) $sideload['error'],
            ]);
            \wp_delete_file($tmp);
            return 0;
        }

        $attachment = [
            'post_mime_type' => $sideload['type'] ?? 'image/jpeg',
            'post_title'     => \sanitize_file_name(\pathinfo($name, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => 0,
        ];

        $attId = (int) \wp_insert_attachment($attachment, $sideload['file'], 0);
        if ($attId <= 0) {
            self::debug('import_attachment_insert_failed', [
                'boat_id'    => $boatId,
                'remote_url' => $remoteUrl,
                'file'       => (string) ($sideload['file'] ?? ''),
            ]);
            return 0;
        }

        \update_post_meta($attId, self::META_ATTACHMENT_REMOTE_URL, $remoteUrl);
        \update_post_meta($attId, self::META_ATTACHMENT_BOAT_ID, $boatId);

        $sizesMap = self::buildIntermediateSizesMap();

        $filter = static function (array $sizes) use ($sizesMap): array {
            return $sizesMap;
        };

        \add_filter('intermediate_image_sizes_advanced', $filter, 999, 1);

        try {
            \add_filter('big_image_size_threshold', '__return_false', 999);

            $metadata = \wp_generate_attachment_metadata($attId, $sideload['file']);
            if (\is_array($metadata)) {
                \wp_update_attachment_metadata($attId, $metadata);
            }

            self::debug('import_attachment_success', [
                'boat_id'       => $boatId,
                'remote_url'    => $remoteUrl,
                'attachment_id' => $attId,
                'file'          => (string) ($sideload['file'] ?? ''),
                'metadata_keys' => \is_array($metadata) ? \array_keys($metadata) : [],
            ]);
        } finally {
            \remove_filter('intermediate_image_sizes_advanced', $filter, 999);
            \remove_filter('big_image_size_threshold', '__return_false', 999);
        }

        return $attId;
    }

    public static function getAttachmentUrlBySize(int $attachmentId, string $sizeName): string
    {
        $attachmentId = (int) $attachmentId;
        $sizeName     = \trim($sizeName);

        if ($attachmentId <= 0 || $sizeName === '') {
            return '';
        }

        if (!isset(self::MARADIGMA_IMAGE_SIZES[$sizeName])) {
            return '';
        }

        $src = \wp_get_attachment_image_src($attachmentId, $sizeName);

        return (\is_array($src) && !empty($src[0]) && \is_string($src[0])) ? (string) $src[0] : '';
    }

    /**
     * @return array<string,string>
     */
    public static function getBoatCoverUrlsByBoatId(string $boatId): array
    {
        $attId = self::getCoverAttachmentIdByBoatId($boatId);

        if ($attId <= 0) {
            return [];
        }

        $out = [];
        foreach (\array_keys(self::MARADIGMA_IMAGE_SIZES) as $sizeName) {
            $url = self::getAttachmentUrlBySize($attId, $sizeName);
            if ($url !== '') {
                $out[$sizeName] = $url;
            }
        }

        return $out;
    }

    /**
     * @param array<string,string> $coverUrls
     */
    public static function pickMainUrlFromCoverUrls(array $coverUrls): string
    {
        if ($coverUrls === []) {
            return '';
        }

        $coverUrls = \array_filter($coverUrls, static fn($u) => \is_string($u) && \trim($u) !== '');
        if ($coverUrls === []) {
            return '';
        }

        $sortByWidthDesc = static function (array $sizeNames): array {
            \usort($sizeNames, static function (string $a, string $b): int {
                $wa = (int) (self::MARADIGMA_IMAGE_SIZES[$a]['w'] ?? 0);
                $wb = (int) (self::MARADIGMA_IMAGE_SIZES[$b]['w'] ?? 0);
                return $wb <=> $wa;
            });
            return $sizeNames;
        };

        $groups = [
            'maradigma_max_'      => [],
            'maradigma_lightbox_' => [],
            'maradigma_hero_'     => [],
            'maradigma_land_'     => [],
            'maradigma_card_'     => [],
            'maradigma_thumb_'    => [],
        ];

        foreach ($coverUrls as $sizeName => $_url) {
            if (!\is_string($sizeName) || $sizeName === '') {
                continue;
            }

            if (!isset(self::MARADIGMA_IMAGE_SIZES[$sizeName])) {
                continue;
            }

            foreach ($groups as $prefix => $_) {
                if (\str_starts_with($sizeName, $prefix)) {
                    $groups[$prefix][] = $sizeName;
                    continue 2;
                }
            }
        }

        foreach ($groups as $list) {
            if ($list === []) {
                continue;
            }

            $list = $sortByWidthDesc($list);

            foreach ($list as $sizeName) {
                $u = $coverUrls[$sizeName] ?? '';
                if (\is_string($u) && $u !== '') {
                    return $u;
                }
            }
        }

        foreach ($coverUrls as $u) {
            if (\is_string($u) && $u !== '') {
                return $u;
            }
        }

        return '';
    }

    public static function getBoatMainUrlByBoatId(string $boatId): string
    {
        $cover = self::getBoatCoverUrlsByBoatId($boatId);
        return self::pickMainUrlFromCoverUrls($cover);
    }

    private static function sanitizeTokenSuffix(string $raw): string
    {
        $raw = \strtolower(\trim($raw));
        if ($raw === '') {
            return '';
        }

        $raw = \preg_replace('/[^a-z0-9_]+/', '_', $raw) ?: '';
        return \trim($raw, '_');
    }

    /**
     * @return string[]
     */
    public static function getAllowedImageTokens(): array
    {
        $out = ['image_main'];

        foreach (\array_keys(self::MARADIGMA_IMAGE_SIZES) as $sizeName) {
            if (!\is_string($sizeName) || $sizeName === '') {
                continue;
            }

            $out[] = 'image_' . self::sanitizeTokenSuffix($sizeName);
        }

        return \array_values(\array_unique($out));
    }

    /**
     * @return array<string,string>
     */
    public static function getElementorImageTokenOptions(): array
    {
        $out = [
            'image_main' => \__('Main image (auto)', 'maradigma'),
        ];

        foreach (self::MARADIGMA_IMAGE_SIZES as $sizeName => $cfg) {
            if (!\is_string($sizeName) || $sizeName === '') {
                continue;
            }

            $w = (int) ($cfg['w'] ?? 0);
            $h = (int) ($cfg['h'] ?? 0);

            $token = 'image_' . self::sanitizeTokenSuffix($sizeName);

            $label = $sizeName;
            if ($w > 0 && $h > 0) {
                $label = \sprintf('%s (%dx%d)', $sizeName, $w, $h);
            } elseif ($w > 0) {
                $label = \sprintf('%s (max width %d)', $sizeName, $w);
            }

            $out[$token] = $label;
        }

        return $out;
    }

    public static function getCoverAttachmentIdByBoatId(string $boatId): int
    {
        $boatId = \trim($boatId);
        if ($boatId === '') {
            return 0;
        }

        $baseArgs = [
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'fields'           => 'ids',
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'lang'             => '',
        ];

        $q1 = new \WP_Query(\array_merge($baseArgs, [
            'meta_query' => [
                ['key' => self::META_ATTACHMENT_BOAT_ID,  'value' => $boatId],
                ['key' => self::META_ATTACHMENT_IS_COVER, 'value' => '1'],
            ],
        ]));

        if (!empty($q1->posts[0])) {
            return (int) $q1->posts[0];
        }

        $q2 = new \WP_Query(\array_merge($baseArgs, [
            'meta_key'   => self::META_ATTACHMENT_IMAGE_ORDER,
            'orderby'    => 'meta_value_num',
            'order'      => 'ASC',
            'meta_query' => [
                ['key' => self::META_ATTACHMENT_BOAT_ID, 'value' => $boatId],
                ['key' => self::META_ATTACHMENT_IMAGE_ORDER, 'compare' => 'EXISTS'],
            ],
        ]));

        if (!empty($q2->posts[0])) {
            return (int) $q2->posts[0];
        }

        $q3 = new \WP_Query(\array_merge($baseArgs, [
            'meta_query' => [
                ['key' => self::META_ATTACHMENT_BOAT_ID, 'value' => $boatId],
            ],
            'orderby' => ['date' => 'DESC'],
        ]));

        return !empty($q3->posts[0]) ? (int) $q3->posts[0] : 0;
    }

    private static function findAttachmentByRemoteUrlAndBoatId(string $remoteUrl, string $boatId): int
    {
        $ids = self::findAttachmentIdsByRemoteUrlAndBoatId($remoteUrl, $boatId);
        return !empty($ids[0]) ? (int) $ids[0] : 0;
    }

    /**
     * @return int[]
     */
    private static function findAttachmentIdsByRemoteUrlAndBoatId(string $remoteUrl, string $boatId): array
    {
        $remoteUrl = \trim($remoteUrl);
        $boatId    = \trim($boatId);

        if ($remoteUrl === '' || $boatId === '') {
            return [];
        }

        $q = new \WP_Query([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'lang'             => '',
            'meta_query'       => [
                ['key' => self::META_ATTACHMENT_REMOTE_URL, 'value' => $remoteUrl],
                ['key' => self::META_ATTACHMENT_BOAT_ID,    'value' => $boatId],
            ],
            'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
        ]);

        if (empty($q->posts) || !\is_array($q->posts)) {
            return [];
        }

        return \array_values(\array_filter(\array_map('intval', $q->posts)));
    }

    private static function cleanupDuplicateAttachmentsByRemoteUrlAndBoatId(string $remoteUrl, string $boatId): void
    {
        $ids = self::findAttachmentIdsByRemoteUrlAndBoatId($remoteUrl, $boatId);

        if (\count($ids) <= 1) {
            return;
        }

        $keepId = (int) \array_shift($ids);

        foreach ($ids as $duplicateId) {
            $duplicateId = (int) $duplicateId;
            if ($duplicateId <= 0) {
                continue;
            }

            $deleted = \wp_delete_attachment($duplicateId, true);

            self::debug('cleanup_duplicate_attachment', [
                'boat_id'               => $boatId,
                'remote_url'            => $remoteUrl,
                'kept_attachment_id'    => $keepId,
                'deleted_attachment_id' => $duplicateId,
                'deleted'               => $deleted ? 1 : 0,
            ]);
        }
    }

    /**
     * Removes cached attachments that no longer belong to the current API image
     * set for this boat. Without this, every historical remote URL can remain in
     * the Media Library forever and the per-boat image counts grow unbounded.
     *
     * @param array<int,array{url:string,number_order:int}> $currentImages
     */
    private static function pruneStaleAttachmentsForBoat(string $boatId, array $currentImages): void
    {
        $boatId = \trim($boatId);
        if ($boatId === '') {
            return;
        }

        $currentUrls = [];
        foreach ($currentImages as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $url = \trim((string) ($row['url'] ?? ''));
            if ($url !== '') {
                $currentUrls[$url] = true;
            }
        }

        $q = new \WP_Query([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'lang'             => '',
            'meta_query'       => [
                [
                    'key'   => self::META_ATTACHMENT_BOAT_ID,
                    'value' => $boatId,
                ],
            ],
        ]);

        if (empty($q->posts) || !\is_array($q->posts)) {
            return;
        }

        foreach ($q->posts as $attId) {
            $attId = (int) $attId;
            if ($attId <= 0) {
                continue;
            }

            $remoteUrl = \trim((string) \get_post_meta($attId, self::META_ATTACHMENT_REMOTE_URL, true));
            if ($remoteUrl !== '' && isset($currentUrls[$remoteUrl])) {
                continue;
            }

            $deleted = \wp_delete_attachment($attId, true);

            self::debug('prune_stale_attachment', [
                'boat_id'       => $boatId,
                'attachment_id' => $attId,
                'remote_url'    => $remoteUrl,
                'deleted'       => $deleted ? 1 : 0,
            ]);
        }
    }

    /**
     * @return int[]
     */
    public static function getOrderedAttachmentIdsByBoatId(string $boatId, int $limit = 50): array
    {
        $boatId = \trim($boatId);
        if ($boatId === '') {
            return [];
        }

        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $q = new \WP_Query([
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'fields'           => 'ids',
            'posts_per_page'   => $limit,
            'no_found_rows'    => true,
            'suppress_filters' => false,
            'lang'             => '',
            'meta_query'       => [
                [
                    'key'   => self::META_ATTACHMENT_BOAT_ID,
                    'value' => $boatId,
                ],
            ],
            'orderby' => ['date' => 'DESC'],
        ]);

        if (empty($q->posts) || !\is_array($q->posts)) {
            return [];
        }

        $ids = \array_values(\array_filter(\array_map('intval', $q->posts)));
        if ($ids === []) {
            return [];
        }

        \usort($ids, static function (int $a, int $b): int {
            $aCover = (int) \get_post_meta($a, self::META_ATTACHMENT_IS_COVER, true);
            $bCover = (int) \get_post_meta($b, self::META_ATTACHMENT_IS_COVER, true);
            if ($aCover !== $bCover) {
                return $bCover <=> $aCover;
            }

            $aOrderRaw = \get_post_meta($a, self::META_ATTACHMENT_IMAGE_ORDER, true);
            $bOrderRaw = \get_post_meta($b, self::META_ATTACHMENT_IMAGE_ORDER, true);
            $aOrder = \is_numeric($aOrderRaw) ? (int) $aOrderRaw : 0;
            $bOrder = \is_numeric($bOrderRaw) ? (int) $bOrderRaw : 0;

            if ($aOrder === 0 && $bOrder !== 0) {
                return 1;
            }
            if ($bOrder === 0 && $aOrder !== 0) {
                return -1;
            }

            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            return $b <=> $a;
        });

        return $ids;
    }

    private static function scheduleNextTick(int $delaySeconds = 2, bool $dispatchAsync = true): void
    {
        $delaySeconds = max(1, $delaySeconds);

        if (!\wp_next_scheduled(self::CRON_HOOK)) {
            \wp_schedule_single_event(\time() + $delaySeconds, self::CRON_HOOK);

            self::debug('cron_scheduled', [
                'hook'          => self::CRON_HOOK,
                'delay_seconds' => $delaySeconds,
            ]);
        } else {
            self::debug('cron_already_scheduled', [
                'hook' => self::CRON_HOOK,
            ]);
        }

        if ($dispatchAsync) {
            self::dispatchAsyncKick($delaySeconds);
        }
    }

    private static function dispatchAsyncKick(int $delaySeconds = 1): void
    {
        $state = self::getState();

        if (($state['status'] ?? '') !== 'running') {
            self::debug('async_kick_not_dispatched_not_running', [
                'state' => $state,
            ]);
            return;
        }

        $url = self::buildAsyncKickUrl($state);
        if ($url === '') {
            self::debug('async_kick_not_dispatched_missing_url');
            return;
        }

        $args = [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => \apply_filters('https_local_ssl_verify', false),
            'body'      => [
                'action'    => self::AJAX_ACTION,
                'async_key' => (string) ($state['async_key'] ?? ''),
                'delay'     => max(0, $delaySeconds),
            ],
        ];

        $state['last_async_kick_at'] = \time();
        $state['last_async_kick_ok'] = 0;
        $state['last_async_kick_error'] = '';
        self::saveState($state);

        $response = \wp_remote_post($url, $args);
        $state = self::getState();
        $state['last_async_kick_at'] = \time();
        $state['last_async_kick_ok'] = \is_wp_error($response) ? 0 : 1;
        $state['last_async_kick_error'] = \is_wp_error($response) ? $response->get_error_message() : '';
        self::saveState($state);

        self::debug('async_kick_dispatched', [
            'url'       => $url,
            'is_error'  => \is_wp_error($response) ? 1 : 0,
            'error'     => \is_wp_error($response) ? $response->get_error_message() : '',
            'delay'     => max(0, $delaySeconds),
        ]);
    }

    private static function buildAsyncKickUrl(array $state): string
    {
        $asyncKey = (string) ($state['async_key'] ?? '');
        if ($asyncKey === '') {
            return '';
        }

        return \admin_url('admin-ajax.php?action=' . self::AJAX_ACTION . '&async_key=' . rawurlencode($asyncKey));
    }

    public static function getNextScheduledAt(): int
    {
        $next = \wp_next_scheduled(self::CRON_HOOK);
        return $next ? (int) $next : 0;
    }

    private static function checkAdminAjaxRequest(): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $nonce = isset($_REQUEST['nonce'])
            ? \sanitize_text_field((string) \wp_unslash($_REQUEST['nonce']))
            : '';
        if (!\wp_verify_nonce($nonce, 'maradigma_images_sync_admin')) {
            \wp_send_json_error(['message' => 'Invalid security token.'], 403);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function getUiStatusPayload(): array
    {
        $state = self::getState();
        $stats = self::getCacheStats();
        $nextCron = self::getNextScheduledAt();
        $lockedUntil = (int) \get_transient(self::LOCK_TRANSIENT . '_until');
        $lastAsyncOk = isset($state['last_async_kick_ok']) ? (int) $state['last_async_kick_ok'] : -1;

        return [
            'state' => $state,
            'stats' => $stats,
            'next_cron_at' => $nextCron,
            'next_cron_label' => $nextCron > 0 ? \date_i18n(\get_option('date_format') . ' ' . \get_option('time_format'), $nextCron) : \__('Not scheduled', 'maradigma'),
            'lock_active' => self::isLockActive() ? 1 : 0,
            'lock_until' => $lockedUntil,
            'worker_label' => self::getWorkerStateLabel($state, $nextCron > 0),
            'async_kick_ok' => $lastAsyncOk,
            'async_kick_label' => self::getAsyncKickLabel($state),
            'async_kick_error' => (string) ($state['last_async_kick_error'] ?? ''),
            'last_run_label' => !empty($state['last_run_at'])
                ? \date_i18n(\get_option('date_format') . ' ' . \get_option('time_format'), (int) $state['last_run_at'])
                : \__('Never', 'maradigma'),
            'last_async_kick_label' => !empty($state['last_async_kick_at'])
                ? \date_i18n(\get_option('date_format') . ' ' . \get_option('time_format'), (int) $state['last_async_kick_at'])
                : \__('Never', 'maradigma'),
        ];
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function getWorkerStateLabel(array $state, bool $hasNextCron): string
    {
        $status = (string) ($state['status'] ?? 'idle');
        $workerState = (string) ($state['worker_state'] ?? '');

        if ($status !== 'running') {
            return $status;
        }

        if ($workerState === 'processing' && self::isLockActive()) {
            return 'processing';
        }

        if ($workerState === 'waiting_lock') {
            return 'waiting for current worker';
        }

        return $hasNextCron ? 'waiting for WP-Cron' : 'running, waiting for next kick';
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function getAsyncKickLabel(array $state): string
    {
        if (empty($state['last_async_kick_at'])) {
            return 'not attempted';
        }

        if (isset($state['last_async_kick_ok']) && (int) $state['last_async_kick_ok'] === 0) {
            return 'failed';
        }

        return 'ok';
    }

    private static function acquireLock(): string
    {
        if (self::isLockActive()) {
            return '';
        }

        $token = \wp_generate_password(20, false, false);
        \set_transient(self::LOCK_TRANSIENT, $token, self::LOCK_TTL_SECONDS);
        \set_transient(self::LOCK_TRANSIENT . '_until', \time() + self::LOCK_TTL_SECONDS, self::LOCK_TTL_SECONDS);

        return \get_transient(self::LOCK_TRANSIENT) === $token ? $token : '';
    }

    private static function releaseLock(string $token): void
    {
        if ($token !== '' && \get_transient(self::LOCK_TRANSIENT) === $token) {
            self::clearLock();
        }
    }

    private static function clearLock(): void
    {
        \delete_transient(self::LOCK_TRANSIENT);
        \delete_transient(self::LOCK_TRANSIENT . '_until');
    }

    private static function isLockActive(): bool
    {
        return (string) \get_transient(self::LOCK_TRANSIENT) !== '';
    }

    private static function isStopRequested(): bool
    {
        $state = self::getState();
        return (string) ($state['status'] ?? '') !== 'running';
    }

    private static function unscheduleAllTicks(): void
    {
        $ts = \wp_next_scheduled(self::CRON_HOOK);
        while ($ts) {
            \wp_unschedule_event($ts, self::CRON_HOOK);
            $ts = \wp_next_scheduled(self::CRON_HOOK);
        }
    }

    private static function registerShutdownHandler(): void
    {
        \register_shutdown_function(static function (): void {
            $error = \error_get_last();
            if (!\is_array($error)) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!\in_array((int) ($error['type'] ?? 0), $fatalTypes, true)) {
                return;
            }

            $state = self::getState();
            if (($state['status'] ?? '') === 'running') {
                $errorMessage = (string) ($error['message'] ?? 'unknown fatal error');
                $currentBoatId = \trim((string) ($state['current_boat_id'] ?? ''));
                $currentImageIndex = max(0, (int) ($state['current_image_index'] ?? 0));
                $currentImagesCount = max(0, (int) ($state['current_boat_images_count'] ?? 0));

                if ($currentBoatId !== '' && $currentImagesCount > 0 && $currentImageIndex < $currentImagesCount) {
                    $state['images_failed'] = max(0, (int) ($state['images_failed'] ?? 0)) + 1;
                    $state['current_image_index'] = $currentImageIndex + 1;
                    $state['worker_state'] = 'recovering';
                    $state['message'] = 'Fatal while processing boat ' . $currentBoatId . ' image ' . ($currentImageIndex + 1) . '/' . $currentImagesCount . '; image skipped: ' . $errorMessage;
                } else {
                    $state['message'] = 'Fatal shutdown detected: ' . $errorMessage;
                }

                self::saveState($state);
                self::clearLock();

                self::debug('shutdown_fatal', [
                    'error' => $error,
                    'state' => $state,
                ]);

                self::scheduleNextTick(5);
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    private static function buildFreshState(bool $forceResync): array
    {
        return [
            'status'                    => 'running',
            'started_at'                => \time(),
            'last_run_at'               => 0,
            'worker_state'              => 'waiting_cron',
            'last_async_kick_at'        => 0,
            'last_async_kick_ok'        => -1,
            'last_async_kick_error'     => '',
            'offset'                    => 0,
            'limit'                     => self::BATCH_BOATS,
            'boats_total'               => 0,
            'boats_processed'           => 0,
            'images_found'              => 0,
            'images_imported'           => 0,
            'images_reused'             => 0,
            'images_failed'             => 0,
            'images_updated_meta'       => 0,
            'force_resync'              => $forceResync ? 1 : 0,
            'message'                   => '',
            'async_key'                 => \wp_generate_password(32, false, false),

            'current_boat_id'           => '',
            'current_boat_offset'       => 0,
            'current_boat_images'       => [],
            'current_boat_images_count' => 0,
            'current_image_index'       => 0,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function normalizeState(array $state): array
    {
        $hasStoredState = $state !== [];
        $defaults = self::buildFreshState(!empty($state['force_resync']));

        if (!$hasStoredState) {
            $defaults['status'] = 'idle';
            $defaults['started_at'] = 0;
            $defaults['worker_state'] = 'idle';
            $defaults['async_key'] = '';
        }

        $asyncKey = \array_key_exists('async_key', $state)
            ? \trim((string) ($state['async_key'] ?? ''))
            : (string) $defaults['async_key'];

        $state = \array_merge($defaults, $state);
        $state['async_key'] = $asyncKey;

        $state['status']                    = (string) ($state['status'] ?? 'stopped');
        $state['started_at']                = (int) ($state['started_at'] ?? 0);
        $state['last_run_at']               = (int) ($state['last_run_at'] ?? 0);
        $state['worker_state']              = (string) ($state['worker_state'] ?? 'waiting_cron');
        $state['last_async_kick_at']        = (int) ($state['last_async_kick_at'] ?? 0);
        $state['last_async_kick_ok']        = (int) ($state['last_async_kick_ok'] ?? -1);
        $state['last_async_kick_error']     = (string) ($state['last_async_kick_error'] ?? '');
        $state['offset']                    = max(0, (int) ($state['offset'] ?? 0));
        $state['limit']                     = max(1, (int) ($state['limit'] ?? self::BATCH_BOATS));
        $state['boats_total']               = max(0, (int) ($state['boats_total'] ?? 0));
        $state['boats_processed']           = max(0, (int) ($state['boats_processed'] ?? 0));
        $state['images_found']              = max(0, (int) ($state['images_found'] ?? 0));
        $state['images_imported']           = max(0, (int) ($state['images_imported'] ?? 0));
        $state['images_reused']             = max(0, (int) ($state['images_reused'] ?? 0));
        $state['images_failed']             = max(0, (int) ($state['images_failed'] ?? 0));
        $state['images_updated_meta']       = max(0, (int) ($state['images_updated_meta'] ?? 0));
        $state['force_resync']              = !empty($state['force_resync']) ? 1 : 0;
        $state['message']                   = (string) ($state['message'] ?? '');

        $state['current_boat_id']           = \trim((string) ($state['current_boat_id'] ?? ''));
        $state['current_boat_offset']       = max(0, (int) ($state['current_boat_offset'] ?? 0));
        $state['current_boat_images']       = \is_array($state['current_boat_images'] ?? null) ? $state['current_boat_images'] : [];
        $state['current_boat_images_count'] = max(0, (int) ($state['current_boat_images_count'] ?? \count($state['current_boat_images'])));
        $state['current_image_index']       = max(0, (int) ($state['current_image_index'] ?? 0));

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function saveState(array $state, bool $force = false): void
    {
        $state = self::normalizeState($state);

        if (!$force && self::isStaleStateWrite($state)) {
            self::debug('state_write_skipped_stale_worker', [
                'incoming_status'    => (string) ($state['status'] ?? ''),
                'incoming_async_key' => !empty($state['async_key']) ? 'present' : 'missing',
            ]);
            return;
        }

        \update_option(self::OPTION_STATE, $state, false);
    }

    /**
     * Prevents an old already-running PHP request from reviving a stopped sync
     * or overwriting a freshly reset/restarted run.
     *
     * @param array<string,mixed> $incoming
     */
    private static function isStaleStateWrite(array $incoming): bool
    {
        $current = self::readRawPersistedState();
        if (!\is_array($current) || $current === []) {
            return false;
        }

        $incomingKey = \trim((string) ($incoming['async_key'] ?? ''));
        $currentKey  = \trim((string) ($current['async_key'] ?? ''));

        if ($incomingKey !== '' && $currentKey === '') {
            return true;
        }

        if ($incomingKey !== '' && $currentKey !== '' && !\hash_equals($currentKey, $incomingKey)) {
            return true;
        }

        $currentStatus  = (string) ($current['status'] ?? '');
        $incomingStatus = (string) ($incoming['status'] ?? '');

        if ($currentStatus === 'stopped' && $incomingStatus === 'running') {
            return true;
        }

        return false;
    }

    /**
     * Reads the persisted sync state directly from wp_options, bypassing the
     * per-request option cache. This is important for long-running workers:
     * another request may have stopped/reset the sync while the worker still
     * has an old option value cached in memory.
     *
     * @return array<string,mixed>
     */
    private static function readRawPersistedState(): array
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            $state = \get_option(self::OPTION_STATE, []);
            return \is_array($state) ? $state : [];
        }

        $raw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::OPTION_STATE
            )
        );

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $value = \maybe_unserialize($raw);
        return \is_array($value) ? $value : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function clearCurrentBoatCheckpoint(array &$state): void
    {
        $state['current_boat_id']           = '';
        $state['current_boat_offset']       = 0;
        $state['current_boat_images']       = [];
        $state['current_boat_images_count'] = 0;
        $state['current_image_index']       = 0;
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function hasActiveBoatCheckpoint(array $state): bool
    {
        $boatId      = \trim((string) ($state['current_boat_id'] ?? ''));
        $images      = \is_array($state['current_boat_images'] ?? null) ? $state['current_boat_images'] : [];
        $imagesCount = (int) ($state['current_boat_images_count'] ?? \count($images));
        $imageIndex  = (int) ($state['current_image_index'] ?? 0);

        if ($boatId === '') {
            return false;
        }

        if ($imagesCount <= 0) {
            return false;
        }

        if ($imageIndex < 0) {
            return false;
        }

        if ($imageIndex >= $imagesCount) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function debug(string $message, array $context = []): void
    {
        Debugger::log(self::DEBUG_CHANNEL, $message, $context);
    }
}
