<?php
declare(strict_types=1);

namespace Maradigma;

use Maradigma\Support\Logger;
use Maradigma\Support\RuntimeContext;
use Maradigma\Support\Sanitizer;

/**
 * Renders plugin templates with cached Maradigma data.
 */
final class Renderer
{
    private TemplateResolver $templates;
    private Cache $cache;

    /**
     * Initializes the renderer.
     */
    public function __construct(TemplateResolver $templates, Cache $cache)
    {
        $this->templates = $templates;
        $this->cache     = $cache;
    }

    /**
     * Renders boats archive/list.
     *
     * @param array<string,mixed> $attrs
     */
    public function renderBoatsArchive(array $attrs = []): string
    {
        $attrs = Sanitizer::attrs($attrs);
        $lang  = Sanitizer::language($attrs['lang'] ?? null);

        // ─────────────────────────────────────────────
        // Boat card selection
        // ─────────────────────────────────────────────
        $cardIdRaw = isset($attrs['card']) ? (string) $attrs['card'] : '';
        $cardIdRaw = trim($cardIdRaw);
        $cardId    = $cardIdRaw !== '' ? $cardIdRaw : null;

        $card = \Maradigma\BoatCardRepository::getCard($cardId)
            ?? \Maradigma\BoatCardRepository::getCard(\Maradigma\BoatCardRepository::DEFAULT_CARD_ID);

        $templateHtml = (string) ($card['template'] ?? '');
        $templateHash = (string) ($card['hash'] ?? '');

        if ($templateHtml === '') {
            $card = \Maradigma\BoatCardRepository::getCard(\Maradigma\BoatCardRepository::DEFAULT_CARD_ID);
            $templateHtml = (string) ($card['template'] ?? '');
            $templateHash = (string) ($card['hash'] ?? '');
        }

        $cacheName = 'boats_archive';

        $attrsForKey = $attrs;
        $attrsForKey['card']      = (string) ($card['id'] ?? \Maradigma\BoatCardRepository::DEFAULT_CARD_ID);
        $attrsForKey['card_hash'] = $templateHash;

        $htmlKey = CachePolicy::key($cacheName, $attrsForKey, $lang);
        $ttl     = CachePolicy::ttl($cacheName);

        if (!RuntimeContext::shouldBypassCache()) {
            $cachedHtml = get_transient($htmlKey);
            if (\is_string($cachedHtml) && $cachedHtml !== '') {
                return $cachedHtml;
            }
        }

        try {
            $result = $this->cache->getBoatsList($attrs);

            $boats = [];
            if (\is_array($result['data']['data'] ?? null)) {
                $boats = $result['data']['data'];
            }

            $engine = new \Maradigma\BoatCardEngine();

            /**
             * IMPORTANT:
             * If public boat pages sync is disabled, do NOT generate public boat base URLs.
             * This prevents the plugin from suggesting or emitting /boats/... links when
             * that feature is disabled.
             */
            $boatsBaseUrl = RuntimeContext::getBoatsBaseUrl($lang);

            $itemsHtml = '';
            foreach ($boats as $boat) {
                if (!\is_array($boat)) {
                    continue;
                }

                $itemsHtml .= $engine->render($templateHtml, $boat, [
                    'boats_base_url' => $boatsBaseUrl,
                    'boats_base_slug' => RuntimeContext::getBoatsBaseSlugForLang($lang),
                    'boat_pages_sync_enabled' => RuntimeContext::isBoatPagesSyncEnabled(),
                ]);
            }

            $html = $this->renderTemplate('boats-archive', [
                'attrs'     => $attrs,
                'lang'      => $lang,
                'boats'     => $boats,
                'itemsHtml' => $itemsHtml,
                'card'      => $card,
                'raw'       => $result,
                'context'   => [
                    'locale'                  => RuntimeContext::getLocale(),
                    'is_builder_preview'      => RuntimeContext::isBuilderPreview(),
                    'boat_pages_sync_enabled' => RuntimeContext::isBoatPagesSyncEnabled(),
                    'boats_base_url'          => $boatsBaseUrl,
                ],
            ]);

            if (!RuntimeContext::shouldBypassCache()) {
                set_transient($htmlKey, $html, $ttl);
            }

            return $html;
        } catch (\Throwable $e) {
            Logger::exception($e, ['component' => 'boats_archive', 'attrs' => $attrs]);

            if (RuntimeContext::isBuilderPreview()) {
                return '<div class="maradigma-notice maradigma-notice--error">Maradigma: Unable to load boats archive (preview mode).</div>';
            }

            return '';
        }
    }

    /**
     * Renders a single boat.
     *
     * @param array<string,mixed> $attrs
     */
    public function renderBoatSingle(array $attrs = []): string
    {
        $attrs = Sanitizer::attrs($attrs);
        $lang  = Sanitizer::language($attrs['lang'] ?? null);

        $idBoat = Sanitizer::id($attrs['id'] ?? null);
        $slug   = Sanitizer::slug($attrs['slug'] ?? null);

        $identifier = $slug ?: ($idBoat ? (string) $idBoat : null);
        if ($identifier === null) {
            return '';
        }

        $paramsForKey = $attrs;
        $paramsForKey['id']   = $idBoat;
        $paramsForKey['slug'] = $slug;

        $cacheName = 'boat_single';
        $htmlKey   = CachePolicy::key($cacheName, $paramsForKey, $lang);
        $ttl       = CachePolicy::ttl($cacheName);

        if (!RuntimeContext::shouldBypassCache()) {
            $cachedHtml = get_transient($htmlKey);
            if (\is_string($cachedHtml) && $cachedHtml !== '') {
                return $cachedHtml;
            }
        }

        try {
            $result = $this->cache->getBoatDetails($identifier, $lang, [
                'expand' => ['service_destination'],
            ]);

            $boat = [];
            if (\is_array($result['data'] ?? null)) {
                $boat = $result['data'];
            }

            $html = $this->renderTemplate('boat-single', [
                'attrs'   => $attrs,
                'lang'    => $lang,
                'boat'    => $boat,
                'raw'     => $result,
                'context' => [
                    'locale'                  => RuntimeContext::getLocale(),
                    'is_builder_preview'      => RuntimeContext::isBuilderPreview(),
                    'boat_pages_sync_enabled' => RuntimeContext::isBoatPagesSyncEnabled(),
                    'boats_base_url'          => RuntimeContext::getBoatsBaseUrl($lang, $boat),
                ],
            ]);

            if (!RuntimeContext::shouldBypassCache()) {
                set_transient($htmlKey, $html, $ttl);
            }

            return $html;
        } catch (\Throwable $e) {
            Logger::exception($e, ['component' => 'boat_single', 'attrs' => $attrs]);

            if (RuntimeContext::isBuilderPreview()) {
                return '<div class="maradigma-notice maradigma-notice--error">Maradigma: Unable to load boat data (preview mode).</div>';
            }

            return '';
        }
    }

    /**
     * @param array<string,mixed> $viewModel
     */
    private function renderTemplate(string $templateName, array $viewModel): string
    {
        $path = $this->templates->resolve($templateName);

        if ($path === null) {
            Logger::warning('Template not found.', ['template' => $templateName]);
            return '';
        }

        $vm = $viewModel;

        \ob_start();
        try {
            include $path;
        } finally {
            $out = (string) \ob_get_clean();
        }

        return $out;
    }
}
