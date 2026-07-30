<?php

declare(strict_types=1);

namespace Maradigma;

use Maradigma\Support\Debugger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps Elementor boat layouts aligned with the configured master template.
 *
 * Editing a synced boat directly in Elementor marks that boat as custom so later
 * template syncs do not overwrite its layout. Editing the Elementor master
 * template starts the shared batched template sync service, matching the flow
 * used by Gutenberg and WPBakery.
 */
final class BoatTemplatePropagator
{
    /** Prevent recursive work while this class writes post meta. */
    private static bool $running = false;

    /** @var array<string,bool> Template content hashes already propagated in this request. */
    private static array $propagatedTemplateHashes = [];

    private const DEBUG_CHANNEL = 'elementor-template-sync';
    private const TEMPLATE_SYNC_BATCH_SIZE = 100;

    public static function init(): void
    {
        add_action('elementor/document/after_save', [__CLASS__, 'onElementorAfterSave'], 10, 2);
        add_action('save_post_elementor_library', [__CLASS__, 'onElementorLibrarySaved'], 99, 3);

        self::debug('init', [
            'did_elementor_loaded' => (int) did_action('elementor/loaded'),
            'debug_enabled' => defined('MARADIGMA_PLUGIN_DEBUG') && MARADIGMA_PLUGIN_DEBUG === true,
        ]);
    }

    /**
     * Handles Elementor's own save event.
     *
     * @param mixed $document Elementor document instance.
     * @param mixed $data     Raw Elementor save payload.
     */
    public static function onElementorAfterSave($document, $data = null): void
    {
        self::debug('after_save_received', [
            'document_type' => is_object($document) ? $document::class : get_debug_type($document),
            'data_keys' => is_array($data) ? array_keys($data) : get_debug_type($data),
            'request_action' => self::requestValue('action'),
            'request_status' => self::requestValue('status'),
        ]);

        unset($data);

        if (!($document instanceof \Elementor\Core\Base\Document)) {
            self::debug('after_save_ignored_invalid_document');
            return;
        }

        $postId = (int) $document->get_main_id();
        self::debug('after_save_document_post', [
            'post_id' => $postId,
            'document_id' => method_exists($document, 'get_id') ? (int) $document->get_id() : 0,
            'post_type' => $postId > 0 ? (string) get_post_type($postId) : '',
            'post_status' => $postId > 0 ? (string) get_post_status($postId) : '',
        ]);

        self::handleSavedPost($postId, 'elementor_after_save');
    }

    /**
     * Fallback for template saves that do not go through Elementor's document hook.
     *
     * @param int      $postId Saved Elementor template post ID.
     * @param \WP_Post $post   Saved post object.
     * @param bool     $update Whether this was an existing post update.
     */
    public static function onElementorLibrarySaved(int $postId, \WP_Post $post, bool $update): void
    {
        self::debug('save_post_elementor_library_received', [
            'post_id' => $postId,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'update' => $update,
            'request_action' => self::requestValue('action'),
        ]);

        if ($post->post_type !== 'elementor_library') {
            self::debug('save_post_ignored_invalid_post_type', [
                'post_id' => $postId,
                'post_type' => $post->post_type,
            ]);
            return;
        }

        self::handleSavedPost($postId, 'save_post_elementor_library');
    }

    private static function handleSavedPost(int $postId, string $source): void
    {
        self::debug('handle_saved_post_start', [
            'source' => $source,
            'post_id' => $postId,
            'running' => self::$running,
            'post_type' => $postId > 0 ? (string) get_post_type($postId) : '',
            'post_status' => $postId > 0 ? (string) get_post_status($postId) : '',
            'is_autosave_or_revision' => $postId > 0 ? self::isAutosaveOrRevision($postId) : null,
        ]);

        if (self::$running) {
            self::debug('handle_saved_post_ignored_running', ['source' => $source, 'post_id' => $postId]);
            return;
        }

        if ($postId <= 0) {
            self::debug('handle_saved_post_ignored_invalid_id', ['source' => $source, 'post_id' => $postId]);
            return;
        }

        if (self::isAutosaveOrRevision($postId)) {
            self::debug('handle_saved_post_ignored_autosave_or_revision', ['source' => $source, 'post_id' => $postId]);
            return;
        }

        $postType = (string) get_post_type($postId);

        if ($postType === BoatPostType::POST_TYPE) {
            self::debug('boat_post_saved_marking_custom', ['source' => $source, 'post_id' => $postId]);
            self::markBoatAsCustomLayout($postId);
            return;
        }

        if ($postType !== 'elementor_library') {
            self::debug('handle_saved_post_ignored_post_type', [
                'source' => $source,
                'post_id' => $postId,
                'post_type' => $postType,
            ]);
            return;
        }

        $masterTemplateId = self::getElementorMasterTemplateId();
        self::debug('master_template_compare', [
            'source' => $source,
            'post_id' => $postId,
            'master_template_id' => $masterTemplateId,
            'matches_master' => $postId === $masterTemplateId,
            'saved_title' => (string) get_the_title($postId),
            'master_title' => $masterTemplateId > 0 ? (string) get_the_title($masterTemplateId) : '',
        ]);

        if ($masterTemplateId <= 0 || $postId !== $masterTemplateId) {
            self::debug('handle_saved_post_ignored_master_mismatch', [
                'source' => $source,
                'post_id' => $postId,
                'master_template_id' => $masterTemplateId,
            ]);
            return;
        }

        $started = self::syncElementorMasterTemplate($masterTemplateId);
        self::debug('sync_elementor_master_returned', [
            'source' => $source,
            'post_id' => $postId,
            'master_template_id' => $masterTemplateId,
            'started' => $started,
        ]);
    }

    /**
     * Starts the shared batched sync for the Elementor master template.
     */
    public static function syncElementorMasterTemplate(int $templateId): bool
    {
        self::debug('sync_start_requested', [
            'template_id' => $templateId,
            'post_type' => $templateId > 0 ? (string) get_post_type($templateId) : '',
            'post_status' => $templateId > 0 ? (string) get_post_status($templateId) : '',
        ]);

        if ($templateId <= 0) {
            self::debug('sync_aborted_invalid_template_id', ['template_id' => $templateId]);
            return false;
        }

        if (!self::canSyncElementorTemplates()) {
            self::debug('sync_aborted_not_allowed', ['template_id' => $templateId]);
            return false;
        }

        $post = get_post($templateId);
        if (!$post || $post->post_type !== 'elementor_library') {
            self::debug('sync_aborted_invalid_template_post', [
                'template_id' => $templateId,
                'post_exists' => (bool) $post,
                'post_type' => $post ? $post->post_type : '',
            ]);
            return false;
        }

        $templateJson = (string) get_post_meta($templateId, '_elementor_data', true);
        $templateHash = $templateJson !== '' ? md5($templateJson) : '';
        self::debug('sync_template_data_loaded', [
            'template_id' => $templateId,
            'template_data_length' => strlen($templateJson),
            'template_hash' => $templateHash,
        ]);

        if ($templateJson === '') {
            self::debug('sync_aborted_empty_template_data', ['template_id' => $templateId]);
            return false;
        }

        $dedupeKey = $templateId . ':' . $templateHash;
        if (isset(self::$propagatedTemplateHashes[$dedupeKey])) {
            self::debug('sync_aborted_duplicate_same_request', [
                'template_id' => $templateId,
                'template_hash' => $templateHash,
            ]);
            return false;
        }
        self::$propagatedTemplateHashes[$dedupeKey] = true;

        if (!class_exists(BoatTemplateSyncAllService::class) || !method_exists(BoatTemplateSyncAllService::class, 'start')) {
            self::debug('sync_aborted_service_unavailable', [
                'service_class_exists' => class_exists(BoatTemplateSyncAllService::class),
            ]);
            return false;
        }

        self::$running = true;

        try {
            self::debug('sync_service_starting', [
                'template_id' => $templateId,
                'batch_size' => self::TEMPLATE_SYNC_BATCH_SIZE,
            ]);

            BoatTemplateSyncAllService::start(self::TEMPLATE_SYNC_BATCH_SIZE, 'elementor', 'overwrite');

            self::debug('sync_service_started', [
                'state' => method_exists(BoatTemplateSyncAllService::class, 'getState') ? BoatTemplateSyncAllService::getState() : [],
            ]);

            if (method_exists(BoatTemplateSyncAllService::class, 'tick')) {
                BoatTemplateSyncAllService::tick();
                self::debug('sync_service_tick_finished', [
                    'state' => method_exists(BoatTemplateSyncAllService::class, 'getState') ? BoatTemplateSyncAllService::getState() : [],
                ]);
            }

            self::regenerateElementorCssSafe($templateId);
            self::clearElementorCacheSafe();
        } catch (\Throwable $e) {
            self::debug('sync_exception', [
                'template_id' => $templateId,
                'exception' => $e,
            ]);
            return false;
        } finally {
            self::$running = false;
        }

        return true;
    }

    private static function getElementorMasterTemplateId(): int
    {
        // This method runs from Elementor save hooks. Creating a missing template
        // here would trigger the same hook again before its option can be stored.
        $templateId = (int) get_option('maradigma_elementor_master_template_id', 0);
        self::debug('master_template_from_option', ['template_id' => $templateId]);
        return $templateId;
    }

    private static function canSyncElementorTemplates(): bool
    {
        if (!class_exists(SettingsPage::class) || !method_exists(SettingsPage::class, 'getSettings')) {
            self::debug('sync_allowed_settings_unavailable');
            return true;
        }

        $settings = SettingsPage::getSettings();
        $enabled = !empty($settings['enable_boat_pages_sync']);
        $builder = sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor'));
        $allowed = $enabled && ($builder === '' || $builder === 'elementor');

        self::debug('sync_allowed_check', [
            'enable_boat_pages_sync' => $settings['enable_boat_pages_sync'] ?? null,
            'boat_layout_builder' => $settings['boat_layout_builder'] ?? null,
            'normalized_builder' => $builder,
            'allowed' => $allowed,
        ]);

        return $allowed;
    }

    private static function markBoatAsCustomLayout(int $postId): void
    {
        if ($postId <= 0 || !class_exists(MetaManager::class)) {
            self::debug('mark_custom_aborted', [
                'post_id' => $postId,
                'metamanager_exists' => class_exists(MetaManager::class),
            ]);
            return;
        }

        $wasCustom = (bool) get_post_meta($postId, MetaManager::META_CPT_ELEMENTOR_CUSTOM_LAYOUT, true);
        if (!$wasCustom) {
            update_post_meta($postId, MetaManager::META_CPT_ELEMENTOR_CUSTOM_LAYOUT, '1');
        }

        self::debug('mark_custom_finished', [
            'post_id' => $postId,
            'was_custom' => $wasCustom,
            'is_custom' => (bool) get_post_meta($postId, MetaManager::META_CPT_ELEMENTOR_CUSTOM_LAYOUT, true),
        ]);
    }

    private static function isAutosaveOrRevision(int $postId): bool
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return true;
        }

        return (bool) wp_is_post_revision($postId) || (bool) wp_is_post_autosave($postId);
    }

    /**
     * Best-effort Elementor CSS regeneration for a post or template.
     */
    private static function regenerateElementorCssSafe(int $postId): void
    {
        try {
            if (!class_exists('\\Elementor\\Core\\Files\\CSS\\Post')) {
                self::debug('css_regeneration_skipped_class_missing', ['post_id' => $postId]);
                return;
            }

            \Elementor\Core\Files\CSS\Post::create($postId)->update();
            self::debug('css_regeneration_finished', ['post_id' => $postId]);
        } catch (\Throwable $e) {
            self::debug('css_regeneration_exception', ['post_id' => $postId, 'exception' => $e]);
        }
    }

    /**
     * Best-effort Elementor cache clear.
     */
    private static function clearElementorCacheSafe(): void
    {
        try {
            if (!class_exists('\\Elementor\\Plugin')) {
                self::debug('cache_clear_skipped_elementor_plugin_missing');
                return;
            }

            $plugin = \Elementor\Plugin::$instance;
            if (isset($plugin->files_manager) && method_exists($plugin->files_manager, 'clear_cache')) {
                $plugin->files_manager->clear_cache();
                self::debug('cache_clear_finished');
                return;
            }

            self::debug('cache_clear_skipped_files_manager_missing');
        } catch (\Throwable $e) {
            self::debug('cache_clear_exception', ['exception' => $e]);
        }
    }

    /** @param array<string,mixed> $context */
    private static function debug(string $message, array $context = []): void
    {
        if (!class_exists(Debugger::class)) {
            return;
        }

        Debugger::log(self::DEBUG_CHANNEL, $message, $context);
    }

    private static function requestValue(string $key): string
    {
        // Elementor owns and validates the save request before invoking these hooks.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (!isset($_REQUEST[$key])) {
            return '';
        }

        $value = sanitize_text_field((string) wp_unslash($_REQUEST[$key]));
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return $value;
    }
}
