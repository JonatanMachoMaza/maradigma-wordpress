import assert from "node:assert/strict";
import test from "node:test";

import {
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
