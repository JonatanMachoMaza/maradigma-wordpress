<?php
declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;

/**
 * Elementor widget: Maradigma Boat Equipments
 */
final class BoatEquipmentsWidget extends BaseSingleBoatWidget
{
    public function get_name(): string
    {
        return 'maradigma_boat_equipments';
    }

    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Equipments', 'maradigma');
    }

    public function get_icon(): string
    {
        return 'eicon-tools';
    }

    public function get_categories(): array
    {
        return ['maradigma'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section(
            'section_content',
            [
                'label' => esc_html__('Content', 'maradigma'),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'title',
            [
                'label'   => esc_html__('Title', 'maradigma'),
                'type'    => Controls_Manager::TEXT,
                'default' => 'Equipments'
            ]
        );

        $this->add_control(
            'show_title',
            [
                'label'        => esc_html__('Show title', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => 'yes',
            ]
        );

        // ✅ Nuevo: mostrar/ocultar icono (OFF por defecto)
        $this->add_control(
            'show_icon',
            [
                'label'        => esc_html__('Show icon', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => '', // ✅ OFF por defecto
            ]
        );

        // ✅ Nuevo: texto/icono configurable (solo si show_icon = yes)
        $this->add_control(
            'icon_text',
            [
                'label'       => esc_html__('Icon text / symbol', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '✓',
                'description' => esc_html__('Example: ✓, ✔, •, →, ★, or any text.', 'maradigma'),
                'condition'   => [
                    'show_icon' => 'yes',
                ],
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_title',
            [
                'label'     => esc_html__('Title', 'maradigma'),
                'tab'       => Controls_Manager::TAB_STYLE,
                'condition' => [
                    'show_title' => 'yes',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'title_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-equipments__title',
            ]
        );

        $this->add_control(
            'title_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-equipments__title' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'title_alignment',
            [
                'label'   => esc_html__('Alignment', 'maradigma'),
                'type'    => Controls_Manager::CHOOSE,
                'options' => [
                    'left' => [
                        'title' => esc_html__('Left', 'maradigma'),
                        'icon'  => 'eicon-text-align-left',
                    ],
                    'center' => [
                        'title' => esc_html__('Center', 'maradigma'),
                        'icon'  => 'eicon-text-align-center',
                    ],
                    'right' => [
                        'title' => esc_html__('Right', 'maradigma'),
                        'icon'  => 'eicon-text-align-right',
                    ],
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-equipments__title' => 'text-align: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'title_spacing',
            [
                'label'      => esc_html__('Bottom spacing', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 100],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-equipments__title' => 'margin-bottom: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->end_controls_section();

        $this->start_controls_section(
            'section_style_items',
            [
                'label' => esc_html__('Items', 'maradigma'),
                'tab'   => Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_responsive_control(
            'items_columns',
            [
                'label'   => esc_html__('Columns', 'maradigma'),
                'type'    => Controls_Manager::SELECT,
                'options' => [
                    '1' => '1',
                    '2' => '2',
                    '3' => '3',
                    '4' => '4',
                    '5' => '5',
                    '6' => '6',
                ],
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-equipments__list' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));',
                ],
            ]
        );

        $this->add_responsive_control(
            'items_row_gap',
            [
                'label'      => esc_html__('Row gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 100],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-equipments__list' => 'row-gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'items_column_gap',
            [
                'label'      => esc_html__('Column gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 100],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-equipments__list' => 'column-gap: {{SIZE}}{{UNIT}};',
                ],
            ]
        );

        $this->add_group_control(
            Group_Control_Typography::get_type(),
            [
                'name'     => 'items_typography',
                'selector' => '{{WRAPPER}} .maradigma-boat-equipments__label',
            ]
        );

        $this->add_control(
            'items_color',
            [
                'label'     => esc_html__('Text color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-equipments__label' => 'color: {{VALUE}};',
                ],
            ]
        );

        $this->add_responsive_control(
            'item_content_gap',
            [
                'label'      => esc_html__('Icon gap', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 0, 'max' => 60],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-equipments__item' => 'column-gap: {{SIZE}}{{UNIT}};',
                ],
                'condition' => [
                    'show_icon' => 'yes',
                ],
            ]
        );

        $this->add_control(
            'icon_color',
            [
                'label'     => esc_html__('Icon color', 'maradigma'),
                'type'      => Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .maradigma-boat-equipments__icon' => 'color: {{VALUE}};',
                ],
                'condition' => [
                    'show_icon' => 'yes',
                ],
            ]
        );

        $this->add_responsive_control(
            'icon_size',
            [
                'label'      => esc_html__('Icon size', 'maradigma'),
                'type'       => Controls_Manager::SLIDER,
                'size_units' => ['px', 'em', 'rem'],
                'range'      => [
                    'px' => ['min' => 8, 'max' => 80],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .maradigma-boat-equipments__icon' => 'font-size: {{SIZE}}{{UNIT}};',
                ],
                'condition' => [
                    'show_icon' => 'yes',
                ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void
    {
        $ctx = $this->resolveContext(['service_equipments']);
        if (!($ctx['ok'] ?? false) || !is_array($ctx['data'] ?? null)) {
            return;
        }

        $boat = (array) $ctx['data'];
        $boatId = (string) ($boat['id'] ?? '');

        if ($boatId === '') {
            return;
        }

        $title = (string) $this->get_settings_for_display('title');
        $showTitle = ((string) $this->get_settings_for_display('show_title')) === 'yes' ? '1' : '0';
        $showIcon = ((string) $this->get_settings_for_display('show_icon')) === 'yes' ? '1' : '0';
        $iconText = (string) $this->get_settings_for_display('icon_text');

        echo wp_kses_post(\Maradigma\ShortcodeRegistry::renderBoatEquipments([
            'id'         => $boatId,
            'title'      => $title,
            'show_title' => $showTitle,
            'show_icon'  => $showIcon,
            'icon_text'  => $iconText,
        ]));
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,string>   $keys
     * @return array<int,string>
     */
    private function extractList(array $data, array $keys): array
    {
        $node = null;
        foreach ($keys as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $node = $data[$k];
                break;
            }
        }

        if (!is_array($node) || $node === []) {
            return [];
        }

        $lang = strtoupper($this->getCurrentLanguageForApi());

        $out = [];
        foreach ($node as $item) {
            if (is_string($item)) {
                $s = trim($item);
                if ($s !== '') {
                    $out[] = $s;
                }
                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $label = $this->pickTranslatedString(
                $item['name'] ?? ($item['title'] ?? ($item['label'] ?? ($item['equipment_name'] ?? null))),
                $item['translations'] ?? null,
                $lang
            );

            $label = trim($label);
            if ($label !== '') {
                $out[] = $label;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param mixed $base
     * @param mixed $translations
     */
    private function pickTranslatedString($base, $translations, string $lang): string
    {
        if (is_array($translations)) {
            if (isset($translations[$lang]) && is_string($translations[$lang]) && trim($translations[$lang]) !== '') {
                return trim($translations[$lang]);
            }
            foreach ($translations as $v) {
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return is_string($base) ? trim($base) : '';
    }
}
