<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsTemplateStats
{
    /**
     * @return array<int,string> postId => label
     */
    public static function getElementorLayoutCandidates(): array
    {
        $query = new \WP_Query([
            'post_type'      => \Maradigma\BoatPostType::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => '_elementor_data',
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ]);

        $posts = \is_array($query->posts) ? $query->posts : [];
        $out = [];

        foreach ($posts as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            $title = \trim((string) \get_the_title($postId));
            if ($title === '') {
                $title = __('(Untitled boat)', 'maradigma');
            }

            $boatId = \trim((string) \get_post_meta($postId, '_maradigma_boat_id', true));
            $isCustom = (bool) \get_post_meta($postId, '_maradigma_elementor_custom_layout', true);

            $label = $title . ' (#' . $postId . ')';
            if ($boatId !== '') {
                $label .= ' · ERP ' . $boatId;
            }
            if ($isCustom) {
                $label .= ' · ' . __('Custom layout', 'maradigma');
            }

            $out[$postId] = $label;
        }

        return $out;
    }

    public static function getMasterTemplateId(): int
    {
        $settings = \Maradigma\SettingsPage::getSettings();
        $builder = sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor'));

        if ($builder === 'gutenberg') {
            $class = self::ensureGutenbergIntegrationClass();
            if ($class !== '' && method_exists($class, 'ensureGutenbergMasterTemplate')) {
                return (int) $class::ensureGutenbergMasterTemplate();
            }

            return 0;
        }

        if ($builder === 'wpbakery') {
            $class = self::ensureWPBakeryIntegrationClass();
            if ($class !== '' && method_exists($class, 'ensureWPBakeryMasterTemplate')) {
                return (int) $class::ensureWPBakeryMasterTemplate();
            }

            return 0;
        }

        $id = (int) get_option('maradigma_elementor_master_template_id', 0);
        if ($id <= 0) {
            return 0;
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== 'elementor_library') {
            return 0;
        }

        return $id;
    }

    /**
     * @return array{
     *   builder:string,
     *   master_id:int,
     *   master_hash:string,
     *   master_edit_url:string,
     *   total:int,
     *   synced:int,
     *   not_synced:int,
     *   custom:int,
     *   missing_data:int
     * }
     */
    public static function getSyncStats(): array
    {
        $settings = \Maradigma\SettingsPage::getSettings();
        $builder = sanitize_key((string) ($settings['boat_layout_builder'] ?? 'elementor'));
        $builder = in_array($builder, ['elementor', 'gutenberg', 'wpbakery'], true) ? $builder : 'elementor';

        $masterId = self::getMasterTemplateId();
        $templateMeta = self::getMasterTemplateMeta($builder, $masterId);
        $postStats = self::getBoatPostStats($builder, $masterId, $templateMeta['hash']);

        return [
            'builder'         => $builder,
            'master_id'       => $masterId,
            'master_hash'     => $templateMeta['hash'],
            'master_edit_url' => $templateMeta['edit_url'],
            'total'           => $postStats['total'],
            'synced'          => $postStats['synced'],
            'not_synced'      => $postStats['not_synced'],
            'custom'          => $postStats['custom'],
            'missing_data'    => $postStats['missing_data'],
        ];
    }

    /**
     * @return array{hash:string,edit_url:string}
     */
    private static function getMasterTemplateMeta(string $builder, int $masterId): array
    {
        if ($masterId <= 0) {
            return ['hash' => '', 'edit_url' => ''];
        }

        if ($builder === 'gutenberg') {
            $class = self::ensureGutenbergIntegrationClass();
            if ($class === '') {
                return ['hash' => '', 'edit_url' => ''];
            }

            $hash = '';
            if (method_exists($class, 'getGutenbergMasterTemplateContent')) {
                $content = (string) $class::getGutenbergMasterTemplateContent();
                if (trim($content) !== '') {
                    $hash = method_exists($class, 'hashGutenbergTemplateContent')
                        ? (string) $class::hashGutenbergTemplateContent($content)
                        : md5($content);
                }
            }

            $editUrl = method_exists($class, 'getGutenbergMasterTemplateEditUrl')
                ? (string) $class::getGutenbergMasterTemplateEditUrl()
                : admin_url('post.php?post=' . $masterId . '&action=edit');

            return ['hash' => $hash, 'edit_url' => $editUrl];
        }

        if ($builder === 'wpbakery') {
            $class = self::ensureWPBakeryIntegrationClass();
            if ($class === '') {
                return ['hash' => '', 'edit_url' => ''];
            }

            $content = method_exists($class, 'getWPBakeryMasterTemplateContent')
                ? (string) $class::getWPBakeryMasterTemplateContent()
                : (string) get_post_field('post_content', $masterId);

            $hash = trim($content) !== ''
                ? (method_exists($class, 'hashWPBakeryTemplateContent') ? (string) $class::hashWPBakeryTemplateContent($content) : md5($content))
                : '';

            $editUrl = method_exists($class, 'getWPBakeryMasterTemplateEditUrl')
                ? (string) $class::getWPBakeryMasterTemplateEditUrl()
                : admin_url('post.php?post=' . $masterId . '&action=edit');

            return ['hash' => $hash, 'edit_url' => $editUrl];
        }

        $templateJson = (string) get_post_meta($masterId, '_elementor_data', true);
        $hash = $templateJson !== '' ? md5($templateJson) : '';

        return [
            'hash'     => $hash,
            'edit_url' => admin_url('post.php?post=' . $masterId . '&action=elementor'),
        ];
    }

    /**
     * @return array{total:int,synced:int,not_synced:int,custom:int,missing_data:int}
     */
    private static function getBoatPostStats(string $builder, int $masterId, string $masterHash): array
    {
        $query = new \WP_Query([
            'post_type'      => \Maradigma\BoatPostType::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ]);

        $posts = is_array($query->posts) ? $query->posts : [];
        $stats = [
            'total'        => count($posts),
            'synced'       => 0,
            'not_synced'   => 0,
            'custom'       => 0,
            'missing_data' => 0,
        ];

        foreach ($posts as $postId) {
            self::collectBoatPostStats((int) $postId, $builder, $masterId, $masterHash, $stats);
        }

        return $stats;
    }

    /**
     * @param array{total:int,synced:int,not_synced:int,custom:int,missing_data:int} $stats
     */
    private static function collectBoatPostStats(int $postId, string $builder, int $masterId, string $masterHash, array &$stats): void
    {
        if ($postId <= 0) {
            return;
        }

        $isCustom = $builder === 'gutenberg'
            ? (bool) get_post_meta($postId, '_maradigma_gutenberg_custom_layout', true)
                || (bool) get_post_meta($postId, '_maradigma_elementor_custom_layout', true)
                || (bool) get_post_meta($postId, '_maradigma_wpbakery_custom_layout', true)
            : ($builder === 'wpbakery'
                ? (bool) get_post_meta($postId, '_maradigma_wpbakery_custom_layout', true)
                : (bool) get_post_meta($postId, '_maradigma_elementor_custom_layout', true));

        if ($isCustom) {
            $stats['custom']++;
            return;
        }

        if ($masterId <= 0 || $masterHash === '') {
            $stats['not_synced']++;
            return;
        }

        if ($builder === 'gutenberg') {
            $content = (string) get_post_field('post_content', $postId);
            $templateHash = trim((string) get_post_meta($postId, '_maradigma_gutenberg_template_hash', true));

            if (trim($content) === '') {
                $stats['missing_data']++;
                $stats['not_synced']++;
                return;
            }

            self::countTemplateHash($templateHash, $masterHash, $stats);
            return;
        }

        if ($builder === 'wpbakery') {
            $content = (string) get_post_field('post_content', $postId);
            $templateHash = trim((string) get_post_meta($postId, '_maradigma_wpbakery_template_hash', true));

            if (trim($content) === '') {
                $stats['missing_data']++;
                $stats['not_synced']++;
                return;
            }

            self::countTemplateHash($templateHash, $masterHash, $stats);
            return;
        }

        $templateHash = trim((string) get_post_meta($postId, '_maradigma_elementor_template_hash', true));
        $hasElementorData = ((string) get_post_meta($postId, '_elementor_data', true)) !== '';

        if (!$hasElementorData) {
            $stats['missing_data']++;
            $stats['not_synced']++;
            return;
        }

        self::countTemplateHash($templateHash, $masterHash, $stats);
    }

    /**
     * @param array{total:int,synced:int,not_synced:int,custom:int,missing_data:int} $stats
     */
    private static function countTemplateHash(string $templateHash, string $masterHash, array &$stats): void
    {
        if ($templateHash !== '' && hash_equals($masterHash, $templateHash)) {
            $stats['synced']++;
        } else {
            $stats['not_synced']++;
        }
    }

    /**
     * @return class-string|''
     */
    private static function ensureWPBakeryIntegrationClass(): string
    {
        $class = \Maradigma\Integrations\WPBakery\WPBakeryIntegration::class;

        if (!class_exists($class)) {
            $file = trailingslashit(MARADIGMA_PLUGIN_DIR) . 'integrations/WPBakery/WPBakeryIntegration.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }

        return class_exists($class) ? $class : '';
    }
    /**
     * @return class-string|''
     */
    private static function ensureGutenbergIntegrationClass(): string
    {
        $class = \Maradigma\Integrations\Gutenberg\GutenbergIntegration::class;

        if (!class_exists($class)) {
            $file = trailingslashit(MARADIGMA_PLUGIN_DIR) . 'integrations/Gutenberg/GutenbergIntegration.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }

        return class_exists($class) ? $class : '';
    }
}
