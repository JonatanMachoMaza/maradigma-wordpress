/**
 * Boats carousel ([maradigma_boats_carousel]).
 *
 * A native horizontal scroller (CSS scroll snap) with previous/next buttons that
 * loads the boats in batches. The server renders the first batch; the next ones
 * come from the boats archive REST endpoint with the carousel's signed scope while
 * the visitor moves through it. Nothing is requested until the visitor uses it.
 *
 * Public API: window.MaradigmaBoatsCarousel.init(root?) initializes the carousels
 * inside root (default: document), for content added after page load.
 */
import {
  buildPageUrl,
  cardsAhead,
  cardsPerView,
  hasMoreBoats,
  pickNewCards,
  scrollDistance,
  shouldLoadMore,
} from './boats-carousel-helpers.mjs';

(function () {
  'use strict';

  const ROOT_SELECTOR = '[data-md-boats-carousel="1"]';
  const INITIALIZED = 'mdCarouselReady';

  function toInt(value, fallback) {
    const parsed = parseInt(String(value ?? ''), 10);
    return Number.isFinite(parsed) ? parsed : fallback;
  }

  function prefersReducedMotion() {
    return typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  /** Identity of a card: its boat ID, or the URL of its first link. */
  function cardKey(element) {
    const withId = element.matches('[data-boat-id]') ? element : element.querySelector('[data-boat-id]');
    const boatId = withId ? String(withId.getAttribute('data-boat-id') || '').trim() : '';
    if (boatId !== '') {
      return 'id:' + boatId;
    }

    const link = element.matches('a[href]') ? element : element.querySelector('a[href]');
    const href = link ? String(link.getAttribute('href') || '').trim() : '';
    return href !== '' && href !== '#' ? 'url:' + href : '';
  }

  /** Cards of a boats archive response, without their listing wrapper. */
  function cardsFromHtml(html) {
    const template = document.createElement('template');
    template.innerHTML = String(html || '');

    const results = template.content.querySelector('.maradigma-boats-archive');
    return results ? Array.from(results.children) : [];
  }

  function initCarousel(root) {
    if (root.dataset[INITIALIZED] === '1') {
      return;
    }

    const track = root.querySelector('[data-md-carousel-track]');
    if (!track) {
      return;
    }

    root.dataset[INITIALIZED] = '1';

    const prevButton = root.querySelector('[data-md-carousel-prev]');
    const nextButton = root.querySelector('[data-md-carousel-next]');

    const query = {
      limit: Math.max(1, toInt(root.dataset.mdCarouselLimit, 8)),
      idGroup: root.dataset.mdCarouselIdGroup || 'boats',
      orderBy: root.dataset.mdCarouselOrderBy || '0',
      card: root.dataset.mdCarouselCard || '',
      imageToken: root.dataset.mdCarouselImageToken || '',
      lang: root.dataset.mdCarouselLang || '',
      scope: root.dataset.mdCarouselScope || '',
    };
    const endpoint = root.dataset.mdCarouselUrl || '';

    const state = {
      page: Math.max(1, toInt(root.dataset.mdCarouselPage, 1)),
      totalPages: Math.max(1, toInt(root.dataset.mdCarouselTotalPages, 1)),
      max: Math.max(0, toInt(root.dataset.mdCarouselMax, 0)),
      loading: false,
      interacted: false,
      advanceWhenLoaded: false,
      seen: new Set(),
    };

    function slides() {
      return Array.from(track.children).filter((slide) => !slide.hasAttribute('data-md-carousel-placeholder'));
    }

    slides().forEach((slide) => {
      const key = cardKey(slide);
      if (key !== '') {
        state.seen.add(key);
      }
    });

    function layout() {
      const first = track.firstElementChild;
      const styles = window.getComputedStyle(track);
      const gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;

      return {
        clientWidth: track.clientWidth,
        scrollWidth: track.scrollWidth,
        scrollLeft: track.scrollLeft,
        slideWidth: first ? first.getBoundingClientRect().width : 0,
        gap,
      };
    }

    function moreBoats() {
      return endpoint !== '' && hasMoreBoats({
        page: state.page,
        totalPages: state.totalPages,
        loaded: slides().length,
        max: state.max,
      });
    }

    function updateButtons() {
      const current = layout();
      const atStart = Math.abs(current.scrollLeft) <= 1;
      const atEnd = current.scrollWidth - Math.abs(current.scrollLeft) - current.clientWidth <= 1;

      if (prevButton) {
        prevButton.disabled = atStart;
      }

      if (nextButton) {
        nextButton.disabled = atEnd && !moreBoats() && !state.loading;
      }

      root.classList.toggle('is-scrollable', current.scrollWidth > current.clientWidth + 1 || moreBoats());
    }

    function addPlaceholders(count) {
      const height = track.firstElementChild ? track.firstElementChild.getBoundingClientRect().height : 0;

      for (let index = 0; index < count; index += 1) {
        const placeholder = document.createElement('li');
        placeholder.className = 'maradigma-boats-carousel__slide maradigma-boats-carousel__slide--placeholder';
        placeholder.setAttribute('data-md-carousel-placeholder', '');
        placeholder.setAttribute('aria-hidden', 'true');
        if (height > 0) {
          placeholder.style.minHeight = Math.round(height) + 'px';
        }
        track.appendChild(placeholder);
      }
    }

    function removePlaceholders() {
      track.querySelectorAll('[data-md-carousel-placeholder]').forEach((placeholder) => placeholder.remove());
    }

    function scrollByView(direction) {
      // In right-to-left pages "next" scrolls towards negative offsets.
      const sign = window.getComputedStyle(track).direction === 'rtl' ? -1 : 1;

      track.scrollBy({
        left: sign * direction * scrollDistance(layout()),
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
      });
    }

    async function loadMore() {
      if (state.loading || !moreBoats()) {
        return;
      }

      state.loading = true;
      root.classList.add('is-loading');
      track.setAttribute('aria-busy', 'true');

      const loaded = slides().length;
      const expected = state.max > 0 ? Math.min(query.limit, state.max - loaded) : query.limit;
      addPlaceholders(Math.max(0, expected));

      try {
        const response = await fetch(
          buildPageUrl(endpoint, { ...query, page: state.page + 1 }),
          { credentials: 'same-origin', headers: { Accept: 'application/json' } }
        );
        const payload = await response.json();

        if (!response.ok || !payload || payload.success !== true || !payload.data) {
          throw new Error('Boats carousel request failed');
        }

        const data = payload.data;
        const cards = cardsFromHtml(data.results_html).map((element) => ({ key: cardKey(element), item: element }));
        const fresh = pickNewCards(state.seen, cards, { loaded, max: state.max });

        removePlaceholders();

        fresh.forEach((card) => {
          const slide = document.createElement('li');
          slide.className = 'maradigma-boats-carousel__slide';
          slide.appendChild(card);
          track.appendChild(slide);
        });

        state.page = Math.max(state.page + 1, toInt(data.current_page, state.page + 1));
        state.totalPages = Math.max(state.page, toInt(data.total_pages, state.totalPages));

        root.dispatchEvent(new CustomEvent('maradigma:boats-carousel-loaded', {
          bubbles: true,
          detail: { page: state.page, added: fresh.length },
        }));
      } catch (error) {
        // Keep the boats already shown; the next interaction retries.
        removePlaceholders();
      } finally {
        state.loading = false;
        root.classList.remove('is-loading');
        track.removeAttribute('aria-busy');
        updateButtons();

        if (state.advanceWhenLoaded) {
          state.advanceWhenLoaded = false;
          scrollByView(1);
        }
      }
    }

    function maybeLoadMore() {
      if (endpoint === '') {
        return;
      }

      const current = layout();

      if (shouldLoadMore({
        loading: state.loading,
        interacted: state.interacted,
        ahead: cardsAhead(current),
        perView: cardsPerView(current),
        page: state.page,
        totalPages: state.totalPages,
        loaded: slides().length,
        max: state.max,
      })) {
        loadMore();
      }
    }

    let frame = 0;
    function onScroll() {
      state.interacted = true;
      if (frame) {
        return;
      }

      frame = window.requestAnimationFrame(() => {
        frame = 0;
        updateButtons();
        maybeLoadMore();
      });
    }

    if (prevButton) {
      prevButton.addEventListener('click', () => {
        state.interacted = true;
        scrollByView(-1);
      });
    }

    if (nextButton) {
      nextButton.addEventListener('click', () => {
        state.interacted = true;
        const current = layout();
        const atEnd = current.scrollWidth - Math.abs(current.scrollLeft) - current.clientWidth <= 1;

        if (atEnd && (state.loading || moreBoats())) {
          // The next cards are on their way: move as soon as they arrive.
          state.advanceWhenLoaded = true;
          loadMore();
          return;
        }

        scrollByView(1);
        maybeLoadMore();
      });
    }

    track.addEventListener('scroll', onScroll, { passive: true });
    track.addEventListener('pointerdown', () => { state.interacted = true; }, { passive: true });
    track.addEventListener('focusin', () => {
      state.interacted = true;
      maybeLoadMore();
    });

    if (typeof window.ResizeObserver === 'function') {
      new window.ResizeObserver(() => updateButtons()).observe(track);
    } else {
      window.addEventListener('resize', updateButtons, { passive: true });
    }

    updateButtons();
    maybeLoadMore();
  }

  function init(context) {
    const scope = context && typeof context.querySelectorAll === 'function' ? context : document;

    if (scope !== document && typeof scope.matches === 'function' && scope.matches(ROOT_SELECTOR)) {
      initCarousel(scope);
    }

    scope.querySelectorAll(ROOT_SELECTOR).forEach(initCarousel);
  }

  window.MaradigmaBoatsCarousel = { init };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => init(document));
  } else {
    init(document);
  }
})();
