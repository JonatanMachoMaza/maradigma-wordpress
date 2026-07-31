<?php

declare(strict_types=1);

namespace Maradigma;

/**
 * Persists and retrieves boat card definitions.
 */
final class BoatCardRepository
{
    public const OPTION_KEY = 'maradigma_boat_cards';

    public const DEFAULT_CARD_ID = 'default';

    /**
     * Estructura:
     * [
     *   'default_card_id' => 'default',
     *   'cards' => [
     *      'default' => [
     *          'name' => 'Default card',
     *          'template' => '<article>...</article>',
     *          'updated_at' => 1700000000
     *      ],
     *      'compact' => [...],
     *   ]
     * ]
     *
     * @return array<string,mixed>
     */
    public static function getConfig(): array
    {
        $cfg = get_option(self::OPTION_KEY, []);
        if (!\is_array($cfg)) {
            $cfg = [];
        }

        if (!isset($cfg['cards']) || !\is_array($cfg['cards'])) {
            $cfg['cards'] = [];
        }

        $cfg['default_card_id'] = \is_scalar($cfg['default_card_id'] ?? null) && \trim((string) $cfg['default_card_id']) !== ''
            ? self::normalizeCardId((string) $cfg['default_card_id'])
            : self::DEFAULT_CARD_ID;

        $normalizedCards = [];
        foreach ($cfg['cards'] as $id => $card) {
            if (!\is_scalar($id) || !\is_array($card)) {
                continue;
            }

            $normalizedCards[self::normalizeCardId((string) $id)] = $card;
        }

        $cfg['cards'] = $normalizedCards;

        // Si no existe la default, la creamos.
        if (!isset($cfg['cards'][self::DEFAULT_CARD_ID]) || !\is_array($cfg['cards'][self::DEFAULT_CARD_ID])) {
            $cfg['cards'][self::DEFAULT_CARD_ID] = [
                'name'       => 'Default card',
                'template'   => self::getDefaultTemplate(),
                'updated_at' => time(),
            ];
            update_option(self::OPTION_KEY, $cfg, false);
        }

        // Si default_card_id apunta a una card que no existe, fallback.
        if (!isset($cfg['cards'][$cfg['default_card_id']])) {
            $cfg['default_card_id'] = self::DEFAULT_CARD_ID;
        }

        return $cfg;
    }

    /**
     * Returns default card ID.
     */
    public static function getDefaultCardId(): string
    {
        $cfg = self::getConfig();
        return (string) $cfg['default_card_id'];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getCard(?string $cardId): ?array
    {
        $cfg = self::getConfig();
        $id  = self::normalizeCardId($cardId ?: $cfg['default_card_id']);

        $card = $cfg['cards'][$id] ?? null;
        if (!\is_array($card)) {
            return null;
        }

        $card['id'] = $id;
        $card['hash'] = self::hashTemplate((string)($card['template'] ?? ''));

        return $card;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function listCards(): array
    {
        $cfg = self::getConfig();
        $out = [];

        foreach (($cfg['cards'] ?? []) as $id => $card) {
            if (!\is_scalar($id) || !\is_array($card)) {
                continue;
            }
            $id = self::normalizeCardId((string) $id);
            $out[$id] = [
                'id'         => $id,
                'name'       => (string)($card['name'] ?? $id),
                'updated_at' => (int)($card['updated_at'] ?? 0),
                'hash'       => self::hashTemplate((string)($card['template'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Persists card.
     */
    public static function saveCard(string $cardId, string $name, string $template): void
    {
        $cfg = self::getConfig();
        $id  = self::normalizeCardId($cardId);

        $cfg['cards'][$id] = [
            'name'       => $name !== '' ? $name : $id,
            'template'   => BoatCardEngine::sanitizeTemplate($template),
            'updated_at' => time(),
        ];

        update_option(self::OPTION_KEY, $cfg, false);
    }

    /**
     * Deletes card.
     */
    public static function deleteCard(string $cardId): void
    {
        $cfg = self::getConfig();
        $id  = self::normalizeCardId($cardId);

        if ($id === self::DEFAULT_CARD_ID) {
            return; // no borrar default
        }

        unset($cfg['cards'][$id]);

        if (($cfg['default_card_id'] ?? '') === $id) {
            $cfg['default_card_id'] = self::DEFAULT_CARD_ID;
        }

        update_option(self::OPTION_KEY, $cfg, false);
    }

    /**
     * Sets default card ID.
     */
    public static function setDefaultCardId(string $cardId): void
    {
        $cfg = self::getConfig();
        $id  = self::normalizeCardId($cardId);

        if (!isset($cfg['cards'][$id])) {
            $id = self::DEFAULT_CARD_ID;
        }

        $cfg['default_card_id'] = $id;
        update_option(self::OPTION_KEY, $cfg, false);
    }

    /**
     * Restores the bundled default boat card template.
     */
    public static function restoreDefaultCardTemplate(): void
    {
        $cfg = self::getConfig();

        $cfg['cards'][self::DEFAULT_CARD_ID] = [
            'name'       => 'Default card',
            'template'   => self::getDefaultTemplate(),
            'updated_at' => time(),
        ];

        update_option(self::OPTION_KEY, $cfg, false);
    }

    /**
     * Normalizes card ID.
     */
    private static function normalizeCardId(string $cardId): string
    {
        $id = strtolower(trim($cardId));
        // slug-ish simple
        $id = preg_replace('/[^a-z0-9\-_]/', '-', $id) ?: self::DEFAULT_CARD_ID;
        $id = trim($id, '-');
        return $id !== '' ? $id : self::DEFAULT_CARD_ID;
    }

    /**
     * Calculates the hash for template.
     */
    private static function hashTemplate(string $template): string
    {
        return hash('sha256', $template);
    }

    /**
     * Returns default template.
     */
    private static function getDefaultTemplate(): string
    {
        $templateFile = dirname(__DIR__) . '/templates/boat-card-default.php';

        if (!is_readable($templateFile)) {
            return '<article class="maradigma-boat-card">{{service_name}}</article>';
        }

        ob_start();
        include $templateFile;
        $template = (string) ob_get_clean();

        return trim($template) !== ''
            ? $template
            : '<article class="maradigma-boat-card">{{service_name}}</article>';
    }
}
