<?php
// integrations/Elementor/Widgets/SingleBoat/BoatGalleryWidget.php

declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;

/**
 * Elementor widget: Maradigma Boat Gallery (grid / slider + optional lightbox)
 *
 * This widget is "shortcode-aligned": it renders the same markup contract as
 * [maradigma_boat_gallery], so the same JS init (data-maradigma-gallery) and CSS
 * (single-boat.css gallery section) apply consistently.
 */
final class BoatGalleryWidget extends BaseSingleBoatWidget
{
    public function get_name(): string
    {
        return 'maradigma_boat_gallery';
    }

    public function get_title(): string
    {
        return \esc_html__('Maradigma Boat Gallery', 'maradigma');
    }

    public function get_icon(): string
    {
        return 'eicon-gallery-grid';
    }

    public function get_categories(): array
    {
        return ['maradigma'];
    }

    /**
     * Encola Swiper + tu init cuando este widget está presente.
     */
    public function get_style_depends(): array
    {
        return ['maradigma-swiper-css'];
    }

    public function get_script_depends(): array
    {
        return ['maradigma-swiper', 'maradigma-boat-gallery'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section(
            'section_content',
            [
                'label' => \esc_html__('Content', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        // Layout
        $this->add_control(
            'layout',
            [
                'label'   => \esc_html__('Layout', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'grid',
                'options' => [
                    'grid'   => \esc_html__('Grid', 'maradigma'),
                    'slider' => \esc_html__('Slider', 'maradigma'),
                ],
            ]
        );

        // Grid columns (solo si grid)
        $this->add_control(
            'columns',
            [
                'label'     => \esc_html__('Columns', 'maradigma'),
                'type'      => Controls_Manager::SELECT,
                'default'   => '3',
                'options'   => [
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                ],
                'condition' => [
                    'layout' => 'grid',
                ],
            ]
        );

        // Lightbox
        $this->add_control(
            'enable_lightbox',
            [
                'label'        => \esc_html__('Enable lightbox on click', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        /**
         * ✅ Panel info (se rellena por JS/AJAX en el editor)
         */
        $this->add_control(
            'images_info',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div class="maradigma-elementor-panel-info" style="padding:8px 10px;border:1px dashed #cfcfcf;background:#fafafa;border-radius:6px;font-size:12px;line-height:1.4;">
                    <strong style="display:block;margin-bottom:4px;">' . \esc_html__('Maradigma', 'maradigma') . '</strong>
                    <span data-maradigma-info="images-count">' . \esc_html__('Images available: …', 'maradigma') . '</span>
                </div>',
                'content_classes' => 'maradigma-raw-html-control',
                'render_type'     => 'template',
            ]
        );

        // ✅ Range
        $this->add_control(
            'start_index',
            [
                'label'   => \esc_html__('Show images from (1-based)', 'maradigma'),
                'type'    => Controls_Manager::NUMBER,
                'min'     => 1,
                'max'     => 999,
                'step'    => 1,
                'default' => 1,
            ]
        );

        $this->add_control(
            'end_index',
            [
                'label'   => \esc_html__('Show images to (0 = last)', 'maradigma'),
                'type'    => Controls_Manager::NUMBER,
                'min'     => 0,
                'max'     => 999,
                'step'    => 1,
                'default' => 0,
            ]
        );

        $this->add_control(
            'max_images',
            [
                'label'       => \esc_html__('Max images (final limit)', 'maradigma'),
                'type'        => Controls_Manager::NUMBER,
                'min'         => 1,
                'max'         => 60,
                'step'        => 1,
                'default'     => 12,
                'description' => \esc_html__('Applied after the from/to range.', 'maradigma'),
            ]
        );

        $this->add_control(
            'prefer_size_key',
            [
                'label'   => \esc_html__('Prefer image size key', 'maradigma'),
                'type'    => Controls_Manager::TEXT,
                'default' => 'large',
            ]
        );

        // -----------------------------------------
        // Slider options (solo si layout = slider)
        // -----------------------------------------
        $this->add_control(
            'slider_autoplay',
            [
                'label'        => \esc_html__('Autoplay', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => '',
                'condition'    => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_delay',
            [
                'label'     => \esc_html__('Autoplay delay (ms)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 500,
                'max'       => 20000,
                'step'      => 100,
                'default'   => 3500,
                'condition' => [
                    'layout'          => 'slider',
                    'slider_autoplay' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'slider_loop',
            [
                'label'        => \esc_html__('Loop', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => \esc_html__('Yes', 'maradigma'),
                'label_off'    => \esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => 'yes',
                'condition'    => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_speed',
            [
                'label'     => \esc_html__('Transition speed (ms)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 100,
                'max'       => 5000,
                'step'      => 50,
                'default'   => 400,
                'condition' => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_space_between',
            [
                'label'     => \esc_html__('Space between slides (px)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 0,
                'max'       => 60,
                'step'      => 1,
                'default'   => 10,
                'condition' => [
                    'layout' => 'slider',
                ],
            ]
        );

        // Slides per view
        $this->add_control(
            'slider_per_view_desktop',
            [
                'label'     => \esc_html__('Slides per view (desktop)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 1,
                'max'       => 6,
                'step'      => 1,
                'default'   => 1,
                'condition' => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_per_view_tablet',
            [
                'label'     => \esc_html__('Slides per view (tablet)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 1,
                'max'       => 6,
                'step'      => 1,
                'default'   => 1,
                'condition' => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_per_view_mobile',
            [
                'label'     => \esc_html__('Slides per view (mobile)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 1,
                'max'       => 6,
                'step'      => 1,
                'default'   => 1,
                'condition' => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_navigation',
            [
                'label'        => \esc_html__('Show arrows', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default'      => 'yes',
                'condition'    => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_pagination',
            [
                'label'        => \esc_html__('Show pagination dots', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default'      => 'yes',
                'condition'    => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_gallery',
            [
                'label' => \esc_html__('Gallery', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        // Keep the original control IDs so existing Elementor documents retain
        // the values that were previously stored in the Content tab.
        $this->add_control(
            'gap',
            [
                'label'     => \esc_html__('Gap (px)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 0,
                'max'       => 60,
                'step'      => 1,
                'default'   => 10,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-gallery' => '--md-gallery-gap: {{VALUE}}px;',
                ],
            ]
        );

        $this->add_control(
            'radius',
            [
                'label'     => \esc_html__('Image radius (px)', 'maradigma'),
                'type'      => Controls_Manager::NUMBER,
                'min'       => 0,
                'max'       => 80,
                'step'      => 1,
                'default'   => 10,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-gallery' => '--md-gallery-radius: {{VALUE}}px;',
                ],
            ]
        );

        $this->add_control(
            'aspect_ratio',
            [
                'label'   => \esc_html__('Aspect ratio', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => '16 / 9',
                'options' => [
                    '1 / 1'  => \esc_html__('Square (1:1)', 'maradigma'),
                    '4 / 3'  => \esc_html__('Classic (4:3)', 'maradigma'),
                    '3 / 2'  => \esc_html__('Photo (3:2)', 'maradigma'),
                    '16 / 9' => \esc_html__('Widescreen (16:9)', 'maradigma'),
                    '21 / 9' => \esc_html__('Panoramic (21:9)', 'maradigma'),
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-gallery' => '--md-gallery-ratio: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'image_fit',
            [
                'label'   => \esc_html__('Image fit', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'cover',
                'options' => [
                    'cover'   => \esc_html__('Cover', 'maradigma'),
                    'contain' => \esc_html__('Contain', 'maradigma'),
                    'fill'    => \esc_html__('Fill', 'maradigma'),
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-gallery__img' => 'object-fit: {{VALUE}};',
                ],
            ]
        );

        $this->add_control(
            'image_position',
            [
                'label'   => \esc_html__('Image position', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'default' => 'center center',
                'options' => [
                    'center center' => \esc_html__('Center', 'maradigma'),
                    'center top'    => \esc_html__('Top', 'maradigma'),
                    'center bottom' => \esc_html__('Bottom', 'maradigma'),
                    'left center'   => \esc_html__('Left', 'maradigma'),
                    'right center'  => \esc_html__('Right', 'maradigma'),
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-gallery__img' => 'object-position: {{VALUE}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Border::get_type(),
            [
                'name'     => 'image_border',
                'selector' => '{{WRAPPER}} .maradigma-boat-gallery__img',
            ]
        );

        $this->add_group_control(
            Group_Control_Box_Shadow::get_type(),
            [
                'name'     => 'image_shadow',
                'selector' => '{{WRAPPER}} .maradigma-boat-gallery__item, {{WRAPPER}} .maradigma-boat-gallery--slider .swiper-slide',
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_slider',
            [
                'label'     => \esc_html__('Slider controls', 'maradigma'),
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => [
                    'layout' => 'slider',
                ],
            ]
        );

        $this->add_control(
            'slider_controls_color',
            [
                'label'     => \esc_html__('Arrows and dots color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-gallery--slider' => '--swiper-theme-color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'slider_bullet_size',
            [
                'label'      => \esc_html__('Pagination dot size', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px'],
                'range'      => [
                    'px' => ['min' => 4, 'max' => 30],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-gallery--slider .swiper-pagination-bullet' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        // Pedimos imágenes desde API
        $ctx = $this->resolveContext(
            ['service_images'],
            [
                'url_images_main_domain' => 1,
                'only_load_cover_image'  => 0,
            ]
        );

        $data = $ctx['data'] ?? null;
        if (!\is_array($data)) {
            return;
        }

        // 1) Prefer images saved in WordPress (attachment IDs stored by the sync)
        $images = null;
        $postId = (int) $this->resolveCurrentPostIdForElementor();
        if ($postId > 0) {
            $ids = get_post_meta($postId, '_maradigma_gallery_attachment_ids', true);
            if (is_array($ids) && !empty($ids)) {
                $tmp = [];
                foreach ($ids as $attId) {
                    $attId = (int) $attId;
                    if ($attId <= 0) continue;

                    $large = wp_get_attachment_image_url($attId, 'large');
                    $full  = wp_get_attachment_image_url($attId, 'full');

                    if (!$large) $large = wp_get_attachment_url($attId);
                    if (!$full)  $full  = $large;
                    if (!$large) continue;

                    $tmp[] = [
                        'url'      => (string) $large,
                        'url_full' => (string) $full,
                    ];
                }
                if (!empty($tmp)) {
                    $images = $tmp;
                }
            }
        }

        // 2) Fallback to API payload
        if ($images === null) {
            $images = $data['images'] ?? $data['service_images'] ?? $data['gallery'] ?? null;
        }

        if (!\is_array($images) || empty($images)) {
            $main = (string) ($data['main_image'] ?? '');
            $main = \trim($main);
            if ($main !== '') {
                $images = [['url' => $main]];
            } else {
                return;
            }
        }

        // Total disponible
        $totalImages = 0;
        if (isset($data['number_images']) && \is_numeric($data['number_images'])) {
            $totalImages = (int) $data['number_images'];
        }
        if ($totalImages <= 0) {
            $totalImages = \count($images);
        }

        // Settings
        $layout = (string) $this->get_settings_for_display('layout');
        if (!\in_array($layout, ['grid', 'slider'], true)) {
            $layout = 'grid';
        }

        $cols = (int) $this->get_settings_for_display('columns');
        if (!\in_array($cols, [2, 3, 4], true)) {
            $cols = 3;
        }

        $enableLightbox = ((string) $this->get_settings_for_display('enable_lightbox') === 'yes');

        $startIndex = (int) $this->get_settings_for_display('start_index'); // 1-based
        $endIndex   = (int) $this->get_settings_for_display('end_index');   // 0 = last
        $max        = (int) $this->get_settings_for_display('max_images');

        if ($startIndex < 1) $startIndex = 1;
        if ($endIndex < 0)   $endIndex = 0;
        if ($max < 1)        $max = 1;

        if ($endIndex === 0) {
            $endIndex = $totalImages > 0 ? $totalImages : \count($images);
        }

        if ($endIndex < $startIndex) {
            $tmp = $startIndex;
            $startIndex = $endIndex;
            $endIndex = $tmp;
        }

        // Clamp
        $available = \count($images);
        if ($startIndex > $available) return;
        if ($endIndex > $available)   $endIndex = $available;

        $offset = $startIndex - 1;
        $length = ($endIndex - $startIndex) + 1;

        $images = \array_slice($images, $offset, $length);
        if (\count($images) > $max) {
            $images = \array_slice($images, 0, $max);
        }

        $preferKey = (string) $this->get_settings_for_display('prefer_size_key');
        $preferKey = \trim($preferKey);

        $alt = (string) ($data['service_name'] ?? $data['name'] ?? 'Boat image');
        $alt = \trim($alt) !== '' ? $alt : 'Boat image';

        // Lightbox slideshow group
        $slideshow = 'maradigma-boat-' . ($postId > 0 ? $postId : \wp_rand(1000, 999999));

        // ✅ Shortcode-aligned CSS vars contract
        $style = '--md-gallery-cols:' . (int)$cols . ';';

        // Helpers: decide thumb/full URL
        $pickFull = static function (array $img): string {
            if (!empty($img['url_full']) && is_string($img['url_full'])) {
                return trim((string)$img['url_full']);
            }
            if (!empty($img['url_sizes']) && is_array($img['url_sizes'])) {
                foreach (['original','full','xxl','xl','large','medium'] as $k) {
                    if (!empty($img['url_sizes'][$k]) && is_string($img['url_sizes'][$k])) {
                        return trim((string)$img['url_sizes'][$k]);
                    }
                }
            }
            if (!empty($img['sizes']) && is_array($img['sizes'])) {
                foreach (['original','full','xxl','xl','large','medium'] as $k) {
                    if (!empty($img['sizes'][$k]) && is_string($img['sizes'][$k])) {
                        return trim((string)$img['sizes'][$k]);
                    }
                }
            }
            return trim((string)($img['url'] ?? ''));
        };

        $pickThumb = static function (array $img, string $preferKey): string {
            if ($preferKey !== '') {
                if (!empty($img['url_sizes']) && is_array($img['url_sizes']) && !empty($img['url_sizes'][$preferKey])) {
                    return trim((string)$img['url_sizes'][$preferKey]);
                }
                if (!empty($img['sizes']) && is_array($img['sizes']) && !empty($img['sizes'][$preferKey])) {
                    return trim((string)$img['sizes'][$preferKey]);
                }
            }
            return trim((string)($img['url'] ?? ''));
        };

        // -----------------------------------------------------------------
        // GRID (shortcode-aligned markup)
        // -----------------------------------------------------------------
        if ($layout === 'grid') {
            echo '<div class="maradigma-boat-gallery maradigma-boat-gallery--grid" style="' . esc_attr($style) . '">';

            foreach ($images as $img) {
                if (!\is_array($img)) continue;

                $thumb = $pickThumb($img, $preferKey);
                if ($thumb === '') continue;

                $full = $pickFull($img);
                if ($full === '') $full = $thumb;

                echo '<figure class="maradigma-boat-gallery__item">';

                if ($enableLightbox) {
                    echo '<a href="' . esc_url($full) . '"
                              data-elementor-open-lightbox="yes"
                              data-elementor-lightbox-slideshow="' . esc_attr($slideshow) . '">';
                }

                // ✅ class-based image (no inline sizing)
                echo '<img class="maradigma-boat-gallery__img" src="' . esc_url($thumb) . '" alt="' . esc_attr($alt) . '" loading="lazy" />';

                if ($enableLightbox) {
                    echo '</a>';
                }

                echo '</figure>';
            }

            echo '</div>';
            return;
        }

        // -----------------------------------------------------------------
        // SLIDER (Swiper) (shortcode-aligned markup + data-maradigma-gallery)
        // -----------------------------------------------------------------
        $autoplay   = ((string)$this->get_settings_for_display('slider_autoplay') === 'yes');
        $delay      = (int)$this->get_settings_for_display('slider_delay');
        $loop       = ((string)$this->get_settings_for_display('slider_loop') === 'yes');
        $speed      = (int)$this->get_settings_for_display('slider_speed');
        $space      = (int)$this->get_settings_for_display('slider_space_between');

        $perDesktop = (int)$this->get_settings_for_display('slider_per_view_desktop');
        $perTablet  = (int)$this->get_settings_for_display('slider_per_view_tablet');
        $perMobile  = (int)$this->get_settings_for_display('slider_per_view_mobile');

        if ($delay < 500)      $delay = 500;
        if ($speed < 100)      $speed = 100;
        if ($perDesktop < 1)   $perDesktop = 1;
        if ($perTablet < 1)    $perTablet = 1;
        if ($perMobile < 1)    $perMobile = 1;

        $showNav  = ((string)$this->get_settings_for_display('slider_navigation') === 'yes');
        $showDots = ((string)$this->get_settings_for_display('slider_pagination') === 'yes');

        $settings = [
            'autoplay'       => $autoplay,
            'delay'          => $delay,
            'loop'           => $loop,
            'speed'          => $speed,
            'spaceBetween'   => $space,
            'perViewDesktop' => $perDesktop,
            'perViewTablet'  => $perTablet,
            'perViewMobile'  => $perMobile,
            'navigation'     => $showNav,
            'pagination'     => $showDots,
            'enableLightbox' => $enableLightbox,
            'slideshow'      => $slideshow,
        ];

        $json = wp_json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo '<div class="maradigma-boat-gallery maradigma-boat-gallery--slider" style="' . esc_attr($style) . '" data-maradigma-gallery="' . esc_attr((string)$json) . '">';

        echo '<div class="swiper maradigma-swiper">';
        echo '<div class="swiper-wrapper">';

        foreach ($images as $img) {
            if (!\is_array($img)) continue;

            $thumb = $pickThumb($img, $preferKey);
            if ($thumb === '') continue;

            $full = $pickFull($img);
            if ($full === '') $full = $thumb;

            echo '<div class="swiper-slide">';

            if ($enableLightbox) {
                echo '<a href="' . esc_url($full) . '"
                          data-elementor-open-lightbox="yes"
                          data-elementor-lightbox-slideshow="' . esc_attr($slideshow) . '">';
            }

            // ✅ class-based image (no inline sizing)
            echo '<img class="maradigma-boat-gallery__img" src="' . esc_url($thumb) . '" alt="' . esc_attr($alt) . '" loading="lazy" />';

            if ($enableLightbox) {
                echo '</a>';
            }

            echo '</div>';
        }

        echo '</div>'; // wrapper

        if ($showDots) {
            echo '<div class="swiper-pagination"></div>';
        }

        if ($showNav) {
            echo '<div class="swiper-button-prev"></div>';
            echo '<div class="swiper-button-next"></div>';
        }

        echo '</div>'; // .swiper
        echo '</div>'; // root
    }
}
