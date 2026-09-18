<?php

declare(strict_types=1);

namespace Maradigma\Support;

/**
 * Markup and value helpers for the extra controls of the boats archive filter form.
 *
 * Every control is enhanced by assets/js/frontend/archive-filters.js through the
 * data-md-* attributes printed here. Values that mean "no filter" are submitted
 * as an empty string so the browser URL and the AJAX request stay clean.
 */
final class ArchiveFilterControls
{
    /**
     * boat_skipper_option codes: 0 = skipper required, 1 = no skipper, 2 = optional skipper.
     * Boats with an optional skipper match both choices.
     */
    public const SKIPPER_CODES_WITH = '0,2';
    public const SKIPPER_CODES_WITHOUT = '1,2';

    private const LENGTH_FALLBACK_MIN = 0;
    private const LENGTH_FALLBACK_MAX = 50;

    /**
     * Highest value offered by the "- / +" selector of a filter field.
     */
    public static function stepperMax(string $fieldKey): int
    {
        return $fieldKey === 'boat_capacity' ? 100 : 10;
    }

    /**
     * Converts a request value into a selector count. Anything that is not a
     * positive whole number means "no filter" (0).
     */
    public static function parseCount(string $raw, int $max): int
    {
        $raw = \trim($raw);

        if ($raw === '' || !\ctype_digit($raw)) {
            return 0;
        }

        return \min((int) $raw, \max(0, $max));
    }

    /**
     * Query parameters (md_* names) written by a filter field of the form.
     *
     * @return list<string>
     */
    public static function queryParamsForField(string $field): array
    {
        return match ($field) {
            'price_range' => ['md_min_price', 'md_max_price'],
            'boat_length' => ['md_min_boat_length', 'md_max_boat_length'],
            default => ['md_' . $field],
        };
    }

    /**
     * Length slider bounds in whole meters, guaranteeing max > min.
     *
     * @param mixed $min
     * @param mixed $max
     * @return array{min:int,max:int}
     */
    public static function normalizeLengthBounds($min, $max): array
    {
        $boundsMin = \is_numeric($min) ? (int) \floor((float) $min) : self::LENGTH_FALLBACK_MIN;
        $boundsMax = \is_numeric($max) ? (int) \ceil((float) $max) : self::LENGTH_FALLBACK_MAX;

        if ($boundsMax <= $boundsMin) {
            $boundsMax = $boundsMin + 1;
        }

        return ['min' => $boundsMin, 'max' => $boundsMax];
    }

    /**
     * Clamps the selected range inside the slider bounds.
     *
     * @return array{min:int,max:int}
     */
    public static function resolveRangeValues(int $boundsMin, int $boundsMax, string $rawMin, string $rawMax): array
    {
        $min = \is_numeric($rawMin) ? (int) $rawMin : $boundsMin;
        $max = \is_numeric($rawMax) ? (int) $rawMax : $boundsMax;

        $min = \max($boundsMin, \min($min, $boundsMax));
        $max = \max($boundsMin, \min($max, $boundsMax));

        if ($max < $min) {
            $max = $min;
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Restores the "with / without skipper" checkboxes from a boat_skipper_option value.
     *
     * @return array{with:bool,without:bool}
     */
    public static function decodeSkipperSelection(string $csv): array
    {
        $codes = [];

        foreach (\explode(',', $csv) as $part) {
            $part = \trim($part);

            if ($part !== '' && \ctype_digit($part)) {
                $codes[(int) $part] = true;
            }
        }

        if ($codes === []) {
            return ['with' => false, 'without' => false];
        }

        $with = isset($codes[0]);
        $without = isset($codes[1]);

        // Only optional-skipper boats (code 2): both choices apply.
        if (!$with && !$without) {
            return ['with' => true, 'without' => true];
        }

        return ['with' => $with, 'without' => $without];
    }

    /**
     * Prints a "- value +" selector. The hidden input carries the value and stays
     * empty while the visible counter shows 0 ("no filter").
     */
    public static function renderStepper(
        string $inputName,
        string $label,
        string $current,
        int $max,
        bool $showLabel = true
    ): void {
        $count = self::parseCount($current, $max);
        $labelId = \wp_unique_id('md-stepper-');

        /* translators: %s: filter name, for example "Cabins". */
        $decreaseLabel = \sprintf(\__('Decrease %s', 'maradigma'), $label);
        /* translators: %s: filter name, for example "Cabins". */
        $increaseLabel = \sprintf(\__('Increase %s', 'maradigma'), $label);
        ?>
        <div
            class="md-stepper"
            role="group"
            <?php if ($showLabel) : ?>
            aria-labelledby="<?php echo \esc_attr($labelId); ?>"
            <?php else : ?>
            aria-label="<?php echo \esc_attr($label); ?>"
            <?php endif; ?>
            data-md-stepper="1"
            data-min="0"
            data-max="<?php echo \esc_attr((string) $max); ?>">
            <?php if ($showLabel) : ?>
                <span class="md-label md-stepper__label" id="<?php echo \esc_attr($labelId); ?>"><?php echo \esc_html($label); ?></span>
            <?php endif; ?>
            <div class="md-stepper__controls">
                <button
                    type="button"
                    class="md-stepper__btn md-stepper__btn--dec"
                    data-md-stepper-dec="1"
                    aria-label="<?php echo \esc_attr($decreaseLabel); ?>"
                    <?php \disabled($count <= 0); ?>>−</button>
                <span class="md-stepper__value" data-md-stepper-value="1" aria-live="polite"><?php echo \esc_html((string) $count); ?></span>
                <button
                    type="button"
                    class="md-stepper__btn md-stepper__btn--inc"
                    data-md-stepper-inc="1"
                    aria-label="<?php echo \esc_attr($increaseLabel); ?>"
                    <?php \disabled($count >= $max); ?>>+</button>
                <input
                    type="hidden"
                    name="<?php echo \esc_attr($inputName); ?>"
                    value="<?php echo \esc_attr($count > 0 ? (string) $count : ''); ?>"
                    data-md-stepper-input="1" />
            </div>
        </div>
        <?php
    }

    /**
     * Prints a double-handle slider for the boat length, in whole meters.
     *
     * The markup reuses the price slider classes so both sliders share the same look.
     */
    public static function renderLengthRange(int $boundsMin, int $boundsMax, string $rawMin, string $rawMax): void
    {
        $values = self::resolveRangeValues($boundsMin, $boundsMax, $rawMin, $rawMax);
        ?>
        <div
            class="maradigma-price-range maradigma-length-range"
            data-md-length-range="1"
            data-range-min="<?php echo \esc_attr((string) $boundsMin); ?>"
            data-range-max="<?php echo \esc_attr((string) $boundsMax); ?>"
            data-step="1"
            data-value-min="<?php echo \esc_attr((string) $values['min']); ?>"
            data-value-max="<?php echo \esc_attr((string) $values['max']); ?>">
            <div class="maradigma-price-range__slider"></div>

            <div class="maradigma-price-range__values">
                <span class="maradigma-price-range__min"><?php echo \esc_html($values['min'] . ' m'); ?></span>
                <span class="maradigma-price-range__sep">—</span>
                <span class="maradigma-price-range__max"><?php echo \esc_html($values['max'] . ' m'); ?></span>
            </div>

            <input type="hidden" name="md_min_boat_length" value="<?php echo \esc_attr((string) $values['min']); ?>" />
            <input type="hidden" name="md_max_boat_length" value="<?php echo \esc_attr((string) $values['max']); ?>" />
        </div>
        <?php
    }

    /**
     * Prints the "with skipper" / "without skipper" choices. The hidden input holds
     * the boat_skipper_option codes; ticking both choices (or none) sends no filter.
     */
    public static function renderSkipperChoices(string $current, bool $showLabel = true): void
    {
        $selection = self::decodeSkipperSelection($current);
        $bothOrNone = ($selection['with'] === $selection['without']);
        $labelId = \wp_unique_id('md-skipper-');
        ?>
        <div
            class="md-skipper"
            role="group"
            <?php if ($showLabel) : ?>
            aria-labelledby="<?php echo \esc_attr($labelId); ?>"
            <?php else : ?>
            aria-label="<?php echo \esc_attr__('Skipper', 'maradigma'); ?>"
            <?php endif; ?>
            data-md-skipper="1"
            data-codes-with="<?php echo \esc_attr(self::SKIPPER_CODES_WITH); ?>"
            data-codes-without="<?php echo \esc_attr(self::SKIPPER_CODES_WITHOUT); ?>">
            <?php if ($showLabel) : ?>
                <span class="md-label md-skipper__label" id="<?php echo \esc_attr($labelId); ?>"><?php \esc_html_e('Skipper', 'maradigma'); ?></span>
            <?php endif; ?>

            <div class="md-option">
                <label class="md-check">
                    <input type="checkbox" data-md-skipper-choice="with" <?php \checked($selection['with']); ?> />
                    <span><?php \esc_html_e('With skipper', 'maradigma'); ?></span>
                </label>
                <p class="md-option__help"><?php \esc_html_e('You will be accompanied by a skipper.', 'maradigma'); ?></p>
            </div>

            <div class="md-option">
                <label class="md-check">
                    <input type="checkbox" data-md-skipper-choice="without" <?php \checked($selection['without']); ?> />
                    <span><?php \esc_html_e('Without skipper', 'maradigma'); ?></span>
                </label>
                <p class="md-option__help"><?php \esc_html_e("You will be the boat's skipper.", 'maradigma'); ?></p>
            </div>

            <input
                type="hidden"
                name="md_boat_skipper_option"
                value="<?php echo \esc_attr($bothOrNone ? '' : ($selection['with'] ? self::SKIPPER_CODES_WITH : self::SKIPPER_CODES_WITHOUT)); ?>"
                data-md-skipper-input="1" />
        </div>
        <?php
    }

    /**
     * Wraps a control in the dropdown pill used by the top filter bar.
     *
     * @param callable():void $renderBody
     */
    public static function renderDropdown(string $pillLabel, string $panelTitle, callable $renderBody): void
    {
        ?>
        <div class="md-filter-dd md-field" data-md-dd="1">
            <button type="button" class="md-filter-pill" data-md-dd-toggle="1" aria-expanded="false">
                <?php echo \esc_html($pillLabel); ?> <span class="md-caret">▾</span>
            </button>

            <div class="md-filter-menu" data-md-dd-panel="1" role="dialog" aria-modal="false">
                <div class="md-filter-menu__inner">
                    <div class="md-filter-menu__title"><?php echo \esc_html($panelTitle); ?></div>
                    <?php $renderBody(); ?>
                </div>

                <div class="md-filter-menu__footer">
                    <button type="button" class="md-dd-clear" data-md-dd-clear="1"><?php \esc_html_e('Clear', 'maradigma'); ?></button>
                    <button type="button" class="md-dd-apply" data-md-dd-apply="1"><?php \esc_html_e('Apply', 'maradigma'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
