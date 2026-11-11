/**
 * Pure helpers of the boats carousel ([maradigma_boats_carousel]).
 *
 * They hold the decisions that do not need the DOM, so they can be tested with
 * node:test: how far an arrow scrolls, how many cards are left ahead, when the next
 * batch must be requested and which loaded cards are new.
 */

/**
 * Width of one card plus the gap that follows it.
 *
 * @param {{slideWidth:number, gap:number}} layout
 * @returns {number}
 */
export function slideStep({ slideWidth, gap }) {
  return Math.max(0, Number(slideWidth) || 0) + Math.max(0, Number(gap) || 0);
}

/**
 * Cards that fit completely in the visible width (at least one).
 *
 * @param {{clientWidth:number, slideWidth:number, gap:number}} layout
 * @returns {number}
 */
export function cardsPerView({ clientWidth, slideWidth, gap }) {
  const step = slideStep({ slideWidth, gap });
  if (step <= 0) {
    return 1;
  }

  // The last visible card needs no gap after it.
  return Math.max(1, Math.floor((Math.max(0, clientWidth) + Math.max(0, gap) + 1) / step));
}

/**
 * Pixels an arrow scrolls: the whole cards of one view, never less than one card.
 *
 * @param {{clientWidth:number, slideWidth:number, gap:number}} layout
 * @returns {number}
 */
export function scrollDistance(layout) {
  return cardsPerView(layout) * slideStep(layout);
}

/**
 * Whole cards still hidden after the visible ones.
 *
 * @param {{scrollWidth:number, scrollLeft:number, clientWidth:number, slideWidth:number, gap:number}} layout
 * @returns {number}
 */
export function cardsAhead({ scrollWidth, scrollLeft, clientWidth, slideWidth, gap }) {
  const step = slideStep({ slideWidth, gap });
  if (step <= 0) {
    return 0;
  }

  const hidden = Math.max(0, scrollWidth - Math.abs(scrollLeft) - clientWidth);
  return Math.floor((hidden + 1) / step);
}

/**
 * Distances from the carousel to the edges of the screen, for bleed="1".
 *
 * The scroller is widened by these distances so it reaches both edges while the
 * first card stays aligned with the content. "start" and "end" follow the reading
 * direction, as the CSS logical properties that use them. They are rounded down:
 * a carousel at a fractional position (31.5px) would otherwise pass the screen
 * edge by a pixel and give the page a horizontal scrollbar.
 *
 * @param {{left:number, right:number, viewportWidth:number, rtl?:boolean}} box
 *        left/right of the carousel's bounding box and the viewport width without scrollbar.
 * @returns {{start:number, end:number}}
 */
export function bleedOffsets({ left, right, viewportWidth, rtl = false }) {
  const toLeft = Math.max(0, Math.floor(Number(left) || 0));
  const toRight = Math.max(0, Math.floor((Number(viewportWidth) || 0) - (Number(right) || 0)));

  return rtl ? { start: toRight, end: toLeft } : { start: toLeft, end: toRight };
}

/**
 * Whether the carousel can still receive boats from the server.
 *
 * @param {{page:number, totalPages:number, loaded:number, max:number}} state
 * @returns {boolean}
 */
export function hasMoreBoats({ page, totalPages, loaded, max }) {
  if (max > 0 && loaded >= max) {
    return false;
  }

  return page < totalPages;
}

/**
 * Whether the next batch must be requested now.
 *
 * Nothing is requested until the visitor uses the carousel, unless the first batch
 * does not even fill the visible width. From then on the next batch is requested
 * while fewer than two views of cards are left ahead, so it is ready in time.
 *
 * @param {{loading:boolean, interacted:boolean, ahead:number, perView:number,
 *          page:number, totalPages:number, loaded:number, max:number}} state
 * @returns {boolean}
 */
export function shouldLoadMore({ loading, interacted, ahead, perView, page, totalPages, loaded, max }) {
  if (loading || !hasMoreBoats({ page, totalPages, loaded, max })) {
    return false;
  }

  const view = Math.max(1, perView);
  if (!interacted) {
    return loaded < view;
  }

  return ahead < view * 2;
}

/**
 * URL of the boats archive request for one page of the carousel.
 *
 * @param {string} endpoint REST URL of maradigma/v1/boats-archive.
 * @param {{page:number, limit:number, idGroup?:string, orderBy?:string, card?:string,
 *          imageToken?:string, lang?:string, scope?:string}} query
 * @returns {string}
 */
export function buildPageUrl(endpoint, { page, limit, idGroup, orderBy, card, imageToken, lang, scope }) {
  const url = new URL(endpoint, 'http://localhost');
  const params = {
    md_page: String(page),
    limit_services: String(limit),
    id_group: idGroup || 'boats',
    md_order_by: orderBy || '0',
    card: card || '',
    image_token: imageToken || '',
    md_lang: lang || '',
    archive_scope: scope || '',
  };

  Object.entries(params).forEach(([key, value]) => {
    if (value !== '') {
      url.searchParams.set(key, value);
    }
  });

  return /^[a-z][a-z0-9+.-]*:/i.test(endpoint) ? url.toString() : url.pathname + url.search;
}

/**
 * Picks the cards of a new batch that are not in the carousel yet, up to the maximum.
 *
 * A boat can appear in two pages when the fleet changes between requests; it is
 * shown once. Cards without a key are always kept.
 *
 * @template T
 * @param {Set<string>} seenKeys Keys of the cards already shown; updated in place.
 * @param {Array<{key:string, item:T}>} cards
 * @param {{loaded:number, max:number}} limits
 * @returns {T[]}
 */
export function pickNewCards(seenKeys, cards, { loaded, max }) {
  const picked = [];

  for (const card of cards) {
    if (max > 0 && loaded + picked.length >= max) {
      break;
    }

    if (card.key !== '') {
      if (seenKeys.has(card.key)) {
        continue;
      }
      seenKeys.add(card.key);
    }

    picked.push(card.item);
  }

  return picked;
}
