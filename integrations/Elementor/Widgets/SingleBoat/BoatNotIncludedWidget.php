<?php
declare(strict_types=1);

namespace Maradigma\Integrations\Elementor\Widgets\SingleBoat;

use Elementor\Controls_Manager;

/**
 * Elementor widget: Maradigma Boat Not Included Items
 */
final class BoatNotIncludedWidget extends BaseSingleBoatWidget
{
    /**
     * Returns the widget's stable Elementor identifier.
     */
    public function get_name(): string
    {
        return 'maradigma_boat_not_included';
    }

    /**
     * Returns the widget title shown in Elementor.
     */
    public function get_title(): string
    {
        return esc_html__('Maradigma Boat Not Included', 'maradigma');
    }

    /**
     * Returns the Elementor icon identifier for the widget.
     */
    public function get_icon(): string
    {
        return 'eicon-close-circle';
    }

    /**
     * Returns the Elementor categories assigned to the widget.
     */
    public function get_categories(): array
    {
        return ['maradigma'];
    }

    /**
     * Registers the controls exposed by the widget.
     */
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
                'default' => 'Not included',
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

        // ✅ Mostrar/ocultar icono X
        $this->add_control(
            'show_cross_icon',
            [
                'label'        => esc_html__('Show cross icon', 'maradigma'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'maradigma'),
                'label_off'    => esc_html__('No', 'maradigma'),
                'return_value' => 'yes',
                'default'      => 'yes', // ✅ por defecto ON
            ]
        );

        // ✅ Nuevo: símbolo/texto configurable para la X
        $this->add_control(
            'cross_text',
            [
                'label'       => esc_html__('Cross text / icon', 'maradigma'),
                'type'        => Controls_Manager::TEXT,
                'default'     => '✕',
                'description' => esc_html__('Example: ✕, ✖, ❌, ×, —, •, or any text.', 'maradigma'),
                'condition'   => [
                    'show_cross_icon' => 'yes',
                ],
            ]
        );

        $this->end_controls_section();
    }

    /**
     * Renders the component output.
     */
    protected function render(): void
    {
        $ctx = $this->resolveContext(['service_not_included_items']);
        if (!($ctx['ok'] ?? false) || !is_array($ctx['data'] ?? null)) {
            return;
        }

        $data  = (array) $ctx['data'];
        $items = $this->extractList($data, ['not_included', 'not_included_items']);

        if ($items === []) {
            echo '<div class="maradigma-boat-not-included maradigma-boat-not-included--empty">';
            echo esc_html__('No "not included" items.', 'maradigma');
            echo '</div>';
            return;
        }

        $showTitle = ((string) $this->get_settings_for_display('show_title')) === 'yes';
        $title = trim((string) $this->get_settings_for_display('title'));
        $title = \Maradigma\Support\MultilangAdapter::translateEditableString(
            $title,
            'Maradigma Elementor Widgets',
            'boat_not_included_title_' . $this->get_id(),
            'maradigma'
        );

        $showCross = ((string) $this->get_settings_for_display('show_cross_icon')) === 'yes';

        // ✅ Nuevo: texto de la X (fallback a ✕)
        $crossText = (string) $this->get_settings_for_display('cross_text');
        $crossText = trim($crossText);
        if ($crossText === '') {
            $crossText = '✕';
        }

        echo '<div class="maradigma-boat-not-included">';

        if ($showTitle && $title !== '') {
            echo '<h3 class="maradigma-boat-not-included__title">' . \esc_html($title) . '</h3>';
        }

        echo '<ul class="maradigma-boat-not-included__list">';
        foreach ($items as $label) {
            echo '<li class="maradigma-boat-not-included__item">';

            if ($showCross) {
                echo '<span class="maradigma-boat-not-included__cross" aria-hidden="true">' . esc_html($crossText) . '</span>';
            }

            echo '<span class="maradigma-boat-not-included__label">' . esc_html($label) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        echo '</div>';
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
                $item['name'] ?? ($item['title'] ?? ($item['label'] ?? ($item['item_name'] ?? ($item['service_name'] ?? null)))),
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
