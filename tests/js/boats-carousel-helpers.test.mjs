import assert from "node:assert/strict";
import test from "node:test";

import {
  arrowsFitBeside,
  bleedOffsets,
  buildPageUrl,
  cardsAhead,
  cardsPerView,
  hasMoreBoats,
  pickNewCards,
  scrollDistance,
  shouldLoadMore,
} from "../../assets/js/frontend/boats-carousel-helpers.mjs";

test("an arrow scrolls the whole cards of one view", () => {
  // 4 cards of 280px with 16px gaps fill 1168px.
  assert.equal(cardsPerView({ clientWidth: 1168, slideWidth: 280, gap: 16 }), 4);
  assert.equal(scrollDistance({ clientWidth: 1168, slideWidth: 280, gap: 16 }), 4 * 296);

  // 1.2 cards per view on a phone: one card per step.
  assert.equal(scrollDistance({ clientWidth: 360, slideWidth: 297, gap: 16 }), 313);

  // No measurable card yet: one card, never zero.
  assert.equal(cardsPerView({ clientWidth: 360, slideWidth: 0, gap: 0 }), 1);
});

test("counts the whole cards still hidden on the right", () => {
  const layout = { clientWidth: 1168, slideWidth: 280, gap: 16 };
  // 8 cards: 8 * 296 - 16 = 2352px.
  assert.equal(cardsAhead({ ...layout, scrollWidth: 2352, scrollLeft: 0 }), 4);
  assert.equal(cardsAhead({ ...layout, scrollWidth: 2352, scrollLeft: 1184 }), 0);
  assert.equal(cardsAhead({ ...layout, scrollWidth: 2352, scrollLeft: 600 }), 1);
});

test("bleed widens the scroller to both screen edges in reading order", () => {
  // A 1232px carousel at 39px from the left edge of a 1310px viewport.
  assert.deepEqual(bleedOffsets({ left: 39, right: 1271, viewportWidth: 1310 }), { start: 39, end: 39 });

  // Right-to-left pages start on the right.
  assert.deepEqual(bleedOffsets({ left: 20, right: 1250, viewportWidth: 1310, rtl: true }), { start: 60, end: 20 });

  // Already at the edges, or wider than the viewport: never negative.
  assert.deepEqual(bleedOffsets({ left: -8, right: 1320, viewportWidth: 1310 }), { start: 0, end: 0 });

  // A carousel at half pixels (Coral Yachting: 31.5px to 1263.5px of 1295px) is
  // rounded down, so the scroller stays inside the screen instead of passing it by 1px.
  assert.deepEqual(bleedOffsets({ left: 31.5, right: 1263.5, viewportWidth: 1295 }), { start: 31, end: 31 });
  assert.deepEqual(bleedOffsets({ left: 20.5, right: 1250.25, viewportWidth: 1310, rtl: true }), { start: 59, end: 20 });
});

test("chevron arrows show only while they fit beside the cards", () => {
  // Coral Yachting at 1920px: a 1776px view centered in 1905px leaves 64.5px a side,
  // room for a 25px arrow 25px away from the cards.
  assert.equal(arrowsFitBeside({ left: 64.5, right: 1840.5, viewportWidth: 1905, need: 50 }), true);
  assert.equal(arrowsFitBeside({ left: 49.6, right: 1855.4, viewportWidth: 1905, need: 50 }), true);

  // A view that fills the screen (1310px, phones) or leaves room on one side only.
  assert.equal(arrowsFitBeside({ left: 0, right: 1295, viewportWidth: 1295, need: 50 }), false);
  assert.equal(arrowsFitBeside({ left: 24, right: 375, viewportWidth: 375, need: 50 }), false);
  assert.equal(arrowsFitBeside({ left: 80, right: 1280, viewportWidth: 1310, need: 50 }), false);

  // Nothing to place.
  assert.equal(arrowsFitBeside({ left: 100, right: 900, viewportWidth: 1000, need: 0 }), false);
});

test("stops asking for boats at the last page or at the maximum", () => {
  assert.equal(hasMoreBoats({ page: 1, totalPages: 3, loaded: 8, max: 0 }), true);
  assert.equal(hasMoreBoats({ page: 3, totalPages: 3, loaded: 24, max: 0 }), false);
  assert.equal(hasMoreBoats({ page: 1, totalPages: 3, loaded: 12, max: 12 }), false);
});

test("requests nothing until the visitor uses the carousel", () => {
  const base = { loading: false, ahead: 4, perView: 4, page: 1, totalPages: 3, loaded: 8, max: 0 };

  assert.equal(shouldLoadMore({ ...base, interacted: false }), false);
  // Unless the first batch does not fill the view.
  assert.equal(shouldLoadMore({ ...base, interacted: false, loaded: 3, ahead: 0 }), true);
});

test("prefetches while fewer than two views are left ahead", () => {
  const base = { loading: false, interacted: true, perView: 4, page: 1, totalPages: 3, loaded: 8, max: 0 };

  assert.equal(shouldLoadMore({ ...base, ahead: 8 }), false);
  assert.equal(shouldLoadMore({ ...base, ahead: 7 }), true);
  assert.equal(shouldLoadMore({ ...base, ahead: 0, loading: true }), false);
  assert.equal(shouldLoadMore({ ...base, ahead: 0, page: 3 }), false);
});

test("builds the archive request of a page with the signed scope", () => {
  const url = buildPageUrl("https://example.test/wp-json/maradigma/v1/boats-archive", {
    page: 2,
    limit: 8,
    orderBy: "0",
    card: "external",
    imageToken: "",
    lang: "es",
    scope: "v1.abc.def",
  });
  const params = Object.fromEntries(new URL(url).searchParams.entries());

  assert.deepEqual(params, {
    md_page: "2",
    limit_services: "8",
    id_group: "boats",
    md_order_by: "0",
    card: "external",
    md_lang: "es",
    archive_scope: "v1.abc.def",
  });
});

test("keeps query-string REST URLs and relative endpoints working", () => {
  const plain = buildPageUrl("https://example.test/?rest_route=/maradigma/v1/boats-archive", { page: 3, limit: 4 });
  const plainUrl = new URL(plain);
  assert.equal(plainUrl.searchParams.get("rest_route"), "/maradigma/v1/boats-archive");
  assert.equal(plainUrl.searchParams.get("md_page"), "3");

  assert.equal(
    buildPageUrl("/wp-json/maradigma/v1/boats-archive", { page: 2, limit: 8 }),
    "/wp-json/maradigma/v1/boats-archive?md_page=2&limit_services=8&id_group=boats&md_order_by=0"
  );
});

test("shows each boat once and never more than the maximum", () => {
  const seen = new Set(["10", "11"]);
  const cards = [
    { key: "11", item: "boat 11 again" },
    { key: "12", item: "boat 12" },
    { key: "", item: "card without id" },
    { key: "13", item: "boat 13" },
  ];

  assert.deepEqual(pickNewCards(seen, cards, { loaded: 2, max: 0 }), ["boat 12", "card without id", "boat 13"]);
  assert.deepEqual([...seen], ["10", "11", "12", "13"]);

  const capped = new Set();
  assert.deepEqual(pickNewCards(capped, [{ key: "1", item: "a" }, { key: "2", item: "b" }], { loaded: 5, max: 6 }), ["a"]);
});
