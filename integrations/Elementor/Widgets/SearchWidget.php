<?php
declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

final class SearchWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'maradigma_boats_search';
    }

    public function get_title(): string
    {
        return \esc_html__('Maradigma - Boats Search', 'maradigma');
    }

    public function get_icon(): string
    {
        return 'eicon-search';
    }

    public function get_categories(): array
    {
        return ['maradigma'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section(
            'section_form',
            [
                'label' => \esc_html__('Form', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'target_url',
            [
                'label' => \esc_html__('Target URL (results page)', 'maradigma'),
                'type' => Controls_Manager::URL,
                'placeholder' => 'https://example.com/boats/',
                'show_external' => false,
                'default' => [
                    'url' => '',
                ],
                'description' => \esc_html__('If empty, submits to current page URL.', 'maradigma'),
            ]
        );

        $this->add_control(
            'show_term',
            [
                'label' => \esc_html__('Show "term" field', 'maradigma'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => '1',
            ]
        );

        $this->add_control(
            'show_capacity',
            [
                'label' => \esc_html__('Show "boat_capacity" field', 'maradigma'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => '1',
            ]
        );

        $this->add_control(
            'show_price',
            [
                'label' => \esc_html__('Show price fields', 'maradigma'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => '1',
            ]
        );

        $this->add_control(
            'show_dates',
            [
                'label' => \esc_html__('Show dates (date_start/date_end)', 'maradigma'),
                'type' => Controls_Manager::SWITCHER,
                'return_value' => '1',
                'default' => '',
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $s = $this->get_settings_for_display();

        $target = (string)($s['target_url']['url'] ?? '');
        $requestUri = isset($_SERVER['REQUEST_URI'])
            ? \sanitize_url((string) \wp_unslash($_SERVER['REQUEST_URI']))
            : '/';
        $action = $target !== '' ? $target : \home_url(\add_query_arg([], $requestUri));

        $showTerm = !empty($s['show_term']);
        $showCap  = !empty($s['show_capacity']);
        $showPrice= !empty($s['show_price']);
        $showDates= !empty($s['show_dates']);

        // Public search filters are read-only and intentionally shareable through the URL.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $term = isset($_GET['term'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['term']))
            : '';
        $capacity = isset($_GET['boat_capacity']) ? \absint(\wp_unslash($_GET['boat_capacity'])) : 0;
        $minPrice = isset($_GET['min_price'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['min_price']))
            : '';
        $maxPrice = isset($_GET['max_price'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['max_price']))
            : '';
        $dateStart = isset($_GET['date_start'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['date_start']))
            : '';
        $dateEnd = isset($_GET['date_end'])
            ? \sanitize_text_field((string) \wp_unslash($_GET['date_end']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        ?>
        <form class="maradigma-search-form" method="get" action="<?php echo \esc_url($action); ?>">
            <?php if ($showTerm) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Search', 'maradigma'); ?></label>
                    <input type="text" name="term" value="<?php echo \esc_attr($term); ?>" />
                </div>
            <?php endif; ?>

            <?php if ($showCap) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Pax', 'maradigma'); ?></label>
                    <input type="number" min="1" name="boat_capacity" value="<?php echo $capacity > 0 ? \esc_attr((string) $capacity) : ''; ?>" />
                </div>
            <?php endif; ?>

            <?php if ($showPrice) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Min price', 'maradigma'); ?></label>
                    <input type="number" min="0" step="1" name="min_price" value="<?php echo \esc_attr($minPrice); ?>" />
                </div>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Max price', 'maradigma'); ?></label>
                    <input type="number" min="0" step="1" name="max_price" value="<?php echo \esc_attr($maxPrice); ?>" />
                </div>
            <?php endif; ?>

            <?php if ($showDates) : ?>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('Start date', 'maradigma'); ?></label>
                    <input type="date" name="date_start" value="<?php echo \esc_attr($dateStart); ?>" />
                </div>
                <div class="maradigma-search-field">
                    <label><?php echo \esc_html__('End date', 'maradigma'); ?></label>
                    <input type="date" name="date_end" value="<?php echo \esc_attr($dateEnd); ?>" />
                </div>
            <?php endif; ?>

            <button type="submit" class="maradigma-search-submit">
                <?php echo \esc_html__('Search', 'maradigma'); ?>
            </button>
        </form>
        <?php
    }
}
