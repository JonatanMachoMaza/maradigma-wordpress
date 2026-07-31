<?php

declare(strict_types=1);

namespace Maradigma\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders and processes the administrative page for available boats.
 */
final class AvailableBoatsPage
{
    private const PAGE_SLUG = 'maradigma-api-boats';

    /**
     * Renders the component output.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        // These GET values only control this read-only admin view and its pagination.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $adminPostUrl = admin_url('admin-post.php');
        $notice = isset($_GET['notice']) ? sanitize_key((string) wp_unslash($_GET['notice'])) : '';

        if ($notice === 'refreshed') {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' .
                esc_html__('Boats list updated successfully.', 'maradigma') .
                '</strong></p></div>';
        } elseif ($notice === 'refresh_error') {
            echo '<div class="notice notice-error is-dismissible"><p><strong>' .
                esc_html__('Could not update the boats list.', 'maradigma') .
                '</strong></p></div>';
        }

        $perPageAllowed = [10, 25, 50, 100, 200];
        $perPage = isset($_GET['per_page']) ? absint(wp_unslash($_GET['per_page'])) : 25;
        if (!in_array($perPage, $perPageAllowed, true)) {
            $perPage = 25;
        }

        $paged = isset($_GET['paged']) ? absint(wp_unslash($_GET['paged'])) : 1;
        if ($paged < 1) {
            $paged = 1;
        }

        $pageSlug = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : self::PAGE_SLUG;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $cache = new \Maradigma\Cache();
        $offset = ($paged - 1) * $perPage;
        $result = $cache->getBoatsList([
            'id_group'        => 'boats',
            'limit_services'  => $perPage,
            'offset_services' => $offset,
        ]);

        $boats = [];
        $total = 0;
        if (!empty($result['success']) && is_array($result['data'] ?? null)) {
            $boats = is_array($result['data']['search_result'] ?? null) ? $result['data']['search_result'] : [];
            $total = isset($result['data']['total_results']) ? (int) $result['data']['total_results'] : 0;
        }

        $totalPages = ($perPage > 0) ? (int) ceil($total / $perPage) : 1;
        $totalPages = max(1, $totalPages);

        if ($paged > $totalPages) {
            $paged = $totalPages;
            $offset = ($paged - 1) * $perPage;

            $result = $cache->getBoatsList([
                'id_group'        => 'boats',
                'limit_services'  => $perPage,
                'offset_services' => $offset,
            ]);

            $boats = [];
            if (!empty($result['success']) && is_array($result['data'] ?? null)) {
                $boats = is_array($result['data']['search_result'] ?? null) ? $result['data']['search_result'] : [];
                $total = isset($result['data']['total_results']) ? (int) $result['data']['total_results'] : $total;
            }
        }

        $from = ($total > 0) ? ($offset + 1) : 0;
        $to = ($total > 0) ? min($offset + count($boats), $total) : 0;
        $paginationHtml = self::renderPagination($paged, $totalPages, $perPage, $pageSlug);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Available boats in Maradigma', 'maradigma') . '</h1>';
        echo '<p class="description">' .
            esc_html__('View the currently available boats and refresh the list to reflect the latest changes.', 'maradigma') .
            '</p>';

        echo '<div class="tablenav top">';
        echo '<div class="alignleft actions">';

        echo '<form method="post" action="' . esc_url($adminPostUrl) . '" style="display:inline-block; margin-right:10px;">';
        wp_nonce_field('maradigma_refresh_api_boats_action', 'maradigma_refresh_api_boats_nonce');
        echo '<input type="hidden" name="action" value="maradigma_refresh_api_boats">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Refresh list', 'maradigma') . '</button>';
        echo '</form>';

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:inline-block;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($pageSlug) . '">';
        echo '<input type="hidden" name="paged" value="1">';
        echo '<label for="maradigma_per_page" style="margin-right:6px;">' . esc_html__('Per page:', 'maradigma') . '</label>';
        echo '<select id="maradigma_per_page" name="per_page" style="min-width:80px; vertical-align:middle;">';
        foreach ($perPageAllowed as $opt) {
            echo '<option value="' . esc_attr((string) $opt) . '"' . selected($perPage, $opt, false) . '>' . esc_html((string) $opt) . '</option>';
        }
        echo '</select> ';
        echo '<button type="submit" class="button" style="vertical-align:middle;">' . esc_html__('Apply', 'maradigma') . '</button>';
        echo '</form>';

        echo '</div>';
        self::renderPaginationSummary($total, $paginationHtml);
        echo '<br class="clear" />';
        echo '</div>';

        echo '<p style="margin-top:10px;">';
        echo '<strong>' . esc_html__('Showing:', 'maradigma') . '</strong> ';
        echo '<code>' . esc_html((string) $from) . '</code> ';
        echo esc_html__('to', 'maradigma') . ' ';
        echo '<code>' . esc_html((string) $to) . '</code> ';
        echo esc_html__('of', 'maradigma') . ' ';
        echo '<code>' . esc_html((string) $total) . '</code>';
        echo '</p>';

        self::renderBoatsTable($boats);

        echo '<div class="tablenav bottom">';
        self::renderPaginationSummary($total, $paginationHtml);
        echo '<br class="clear" />';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Refreshes the available data from the external API.
     */
    public static function refresh(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_refresh_api_boats_action', 'maradigma_refresh_api_boats_nonce');

        try {
            \Maradigma\Cache::flushAll();
        } catch (\Throwable $e) {
            maradigma_debug_log('[Maradigma] flushAll failed: ' . $e->getMessage());
        }

        try {
            $client = \Maradigma\SettingsPage::makeExternalApiClient();
            $auth = $client->testAuth();
            if (empty($auth['status']) || $auth['status'] !== 'success') {
                throw new \RuntimeException((string) ($auth['message'] ?? 'Invalid credentials'));
            }

            $cache = new \Maradigma\Cache();
            $prime = $cache->getBoatsList([
                'id_group'        => 'boats',
                'limit_services'  => 25,
                'offset_services' => 0,
            ]);

            if (empty($prime['success'])) {
                throw new \RuntimeException('Could not refresh boats list');
            }

            wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'notice' => 'refreshed'], admin_url('admin.php')));
            exit;
        } catch (\Throwable $e) {
            maradigma_debug_log('[Maradigma] refresh boats list error: ' . $e->getMessage());
            wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG, 'notice' => 'refresh_error'], admin_url('admin.php')));
            exit;
        }
    }

    /**
     * Renders pagination.
     */
    private static function renderPagination(int $current, int $pages, int $perPage, string $pageSlug): string
    {
        if ($pages <= 1) {
            return '';
        }

        $current = max(1, min($current, $pages));
        $buildUrl = static function (int $page) use ($pageSlug, $perPage): string {
            return add_query_arg(
                [
                    'page'     => $pageSlug,
                    'paged'    => max(1, $page),
                    'per_page' => $perPage,
                ],
                admin_url('admin.php')
            );
        };

        $disableFirstPrev = ($current <= 1);
        $disableNextLast = ($current >= $pages);

        ob_start();
        ?>
        <span class="pagination-links">
            <?php if ($disableFirstPrev) : ?>
                <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
                <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
            <?php else : ?>
                <a class="first-page button" href="<?php echo esc_url($buildUrl(1)); ?>">
                    <span class="screen-reader-text"><?php echo esc_html__('First page', 'maradigma'); ?></span>
                    <span aria-hidden="true">&laquo;</span>
                </a>
                <a class="prev-page button" href="<?php echo esc_url($buildUrl(max(1, $current - 1))); ?>">
                    <span class="screen-reader-text"><?php echo esc_html__('Previous page', 'maradigma'); ?></span>
                    <span aria-hidden="true">&lsaquo;</span>
                </a>
            <?php endif; ?>

            <span class="paging-input">
                <label for="current-page-selector" class="screen-reader-text"><?php echo esc_html__('Current page', 'maradigma'); ?></label>
                <span class="tablenav-paging-text">
                    <input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr((string) $current); ?>" size="1" aria-describedby="table-paging" />
                    <span class="tablenav-paging-text">
                        <?php
                        /* translators: %s: total pages */
                        echo esc_html(sprintf(__('of %s', 'maradigma'), number_format_i18n($pages)));
                        ?>
                    </span>
                </span>
            </span>

            <?php if ($disableNextLast) : ?>
                <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
                <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
            <?php else : ?>
                <a class="next-page button" href="<?php echo esc_url($buildUrl(min($pages, $current + 1))); ?>">
                    <span class="screen-reader-text"><?php echo esc_html__('Next page', 'maradigma'); ?></span>
                    <span aria-hidden="true">&rsaquo;</span>
                </a>
                <a class="last-page button" href="<?php echo esc_url($buildUrl($pages)); ?>">
                    <span class="screen-reader-text"><?php echo esc_html__('Last page', 'maradigma'); ?></span>
                    <span aria-hidden="true">&raquo;</span>
                </a>
            <?php endif; ?>
        </span>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders pagination summary.
     */
    private static function renderPaginationSummary(int $total, string $paginationHtml): void
    {
        echo '<div class="tablenav-pages" id="table-paging">';
        echo '<span class="displaying-num">' . esc_html(sprintf(
            /* translators: %d: total number of boats. */
            __('%d boats', 'maradigma'),
            $total
        )) . '</span>';
        if ($paginationHtml !== '') {
            echo wp_kses_post($paginationHtml);
        }
        echo '</div>';
    }

    /**
     * @param array<int,array<string,mixed>> $boats
     */
    private static function renderBoatsTable(array $boats): void
    {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th style="width:90px;">' . esc_html__('ID', 'maradigma') . '</th>';
        echo '<th>' . esc_html__('Name', 'maradigma') . '</th>';
        echo '<th>' . esc_html__('Home port', 'maradigma') . '</th>';
        echo '<th style="width:110px;">' . esc_html__('Capacity', 'maradigma') . '</th>';
        echo '<th style="width:110px;">' . esc_html__('Length', 'maradigma') . '</th>';
        echo '<th style="width:110px;">' . esc_html__('Cabins', 'maradigma') . '</th>';
        echo '<th style="width:160px;">' . esc_html__('Status', 'maradigma') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($boats)) {
            echo '<tr><td colspan="7">' . esc_html__('No boats available to display.', 'maradigma') . '</td></tr>';
        } else {
            foreach ($boats as $boat) {
                self::renderBoatRow($boat);
            }
        }

        echo '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $boat
     */
    private static function renderBoatRow(array $boat): void
    {
        $id = isset($boat['id']) ? (string) $boat['id'] : '-';
        $name = (string) ($boat['service_name'] ?? $boat['name'] ?? $boat['boat_alias'] ?? '-');
        $port = (string) ($boat['boat_base_port_name'] ?? $boat['port'] ?? '-');
        $capacity = isset($boat['boat_capacity']) ? (string) $boat['boat_capacity'] : '-';
        $length = isset($boat['boat_length']) ? (string) $boat['boat_length'] : '-';
        $cabins = isset($boat['boat_cabins']) ? (string) $boat['boat_cabins'] : '-';

        $statusInt = is_numeric($boat['status'] ?? null) ? (int) $boat['status'] : null;
        $statusLabel = match ($statusInt) {
            1 => __('Active', 'maradigma'),
            0 => __('Inactive', 'maradigma'),
            default => __('Unknown', 'maradigma'),
        };

        $featuredInt = is_numeric($boat['featured'] ?? null) ? (int) $boat['featured'] : null;
        $featuredLabel = match ($featuredInt) {
            1 => __('Featured', 'maradigma'),
            0 => __('Not featured', 'maradigma'),
            default => __('Featured: unknown', 'maradigma'),
        };

        echo '<tr>';
        echo '<td><code>' . esc_html($id) . '</code></td>';
        echo '<td>' . esc_html($name) . '</td>';
        echo '<td>' . esc_html($port) . '</td>';
        echo '<td>' . esc_html($capacity) . '</td>';
        echo '<td>' . esc_html($length) . '</td>';
        echo '<td>' . esc_html($cabins) . '</td>';
        echo '<td>' . esc_html($statusLabel . ' - ' . $featuredLabel) . '</td>';
        echo '</tr>';
    }
}
