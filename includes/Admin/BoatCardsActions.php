<?php

declare(strict_types=1);

namespace Maradigma\Admin;

use Maradigma\BoatCardEngine;

if (!defined('ABSPATH')) {
    exit;
}

final class BoatCardsActions
{
    public static function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('maradigma_boat_cards_action', 'maradigma_nonce');

        $action = isset($_POST['maradigma_action']) ? sanitize_key((string) wp_unslash($_POST['maradigma_action'])) : '';
        $cardId = isset($_POST['card_id']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['card_id']))) : '';
        $redirectArgs = [
            'page' => 'maradigma-settings',
            'tab'  => 'cards',
        ];

        if ($action === 'save_card') {
            $cardName = isset($_POST['card_name']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['card_name']))) : '';
            $template = isset($_POST['card_template'])
                ? wp_kses(
                    (string) wp_unslash($_POST['card_template']),
                    BoatCardEngine::getAllowedHtml()
                )
                : '';
            $setDefault = isset($_POST['set_default'])
                && sanitize_key((string) wp_unslash($_POST['set_default'])) === '1';

            $redirectArgs = self::saveCard($redirectArgs, $cardId, $cardName, $template, $setDefault);
        } elseif ($action === 'delete_card') {
            $redirectArgs = self::deleteCard($redirectArgs, $cardId);
        } elseif ($action === 'set_default') {
            $redirectArgs = self::setDefaultCard($redirectArgs, $cardId);
        } elseif ($action === 'restore_default_card') {
            $redirectArgs = self::restoreDefaultCard($redirectArgs);
        }

        wp_safe_redirect(add_query_arg($redirectArgs, admin_url('admin.php')));
        exit;
    }

    /**
     * @param array<string,string> $redirectArgs
     * @param string $cardId
     * @param string $cardName
     * @param string $template
     * @param bool $setDefault
     * @return array<string,string>
     */
    private static function saveCard(
        array $redirectArgs,
        string $cardId,
        string $cardName,
        string $template,
        bool $setDefault
    ): array
    {
        if ($cardId !== '' && $template !== '') {
            \Maradigma\BoatCardRepository::saveCard($cardId, $cardName, $template);

            if ($setDefault) {
                \Maradigma\BoatCardRepository::setDefaultCardId($cardId);
            }

            $redirectArgs['edit_card'] = $cardId;
            $redirectArgs['notice'] = 'saved';

            return $redirectArgs;
        }

        $redirectArgs['edit_card'] = ($cardId !== '') ? $cardId : 'new';
        $redirectArgs['notice'] = 'invalid';

        return $redirectArgs;
    }

    /**
     * @param array<string,string> $redirectArgs
     * @param string $cardId
     * @return array<string,string>
     */
    private static function deleteCard(array $redirectArgs, string $cardId): array
    {
        if ($cardId !== '') {
            \Maradigma\BoatCardRepository::deleteCard($cardId);
            $redirectArgs['notice'] = 'deleted';
        } else {
            $redirectArgs['notice'] = 'invalid';
        }

        return $redirectArgs;
    }

    /**
     * @param array<string,string> $redirectArgs
     * @param string $cardId
     * @return array<string,string>
     */
    private static function setDefaultCard(array $redirectArgs, string $cardId): array
    {
        if ($cardId !== '') {
            \Maradigma\BoatCardRepository::setDefaultCardId($cardId);
            $redirectArgs['notice'] = 'default';
        } else {
            $redirectArgs['notice'] = 'invalid';
        }

        return $redirectArgs;
    }

    /**
     * @param array<string,string> $redirectArgs
     * @return array<string,string>
     */
    private static function restoreDefaultCard(array $redirectArgs): array
    {
        \Maradigma\BoatCardRepository::restoreDefaultCardTemplate();

        $redirectArgs['edit_card'] = \Maradigma\BoatCardRepository::DEFAULT_CARD_ID;
        $redirectArgs['notice'] = 'restored';

        return $redirectArgs;
    }
}
