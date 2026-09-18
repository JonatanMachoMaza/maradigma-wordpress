import assert from "node:assert/strict";
import test from "node:test";

import {
  ARCHIVE_UI_CONFIG_PARAMS,
  buildBrowserUrl,
  buildSkipperOptionValue,
  isArchiveUiConfigParam,
  stepStepperValue,
} from "../../assets/js/frontend/archive-filters-helpers.mjs";

const PAGE = "https://example.test/en/boats/";

function searchParams(url) {
  return Object.fromEntries(new URL(url).searchParams.entries());
}

test("keeps the archive UI configuration out of the browser URL", () => {
  const url = buildBrowserUrl(PAGE, {
    md_page: "1",
    md_featured: "1",
    filters_ui_fields: "date_start,boat_capacity",
    filters_ui_fields_right: "order_by",
    filters_ui_fields_offcanvas: "term,min_price",
    filters_ui_layout: "horizontal",
    filters_ui_submit_mode: "auto",
    filters_ui_show_reset: "1",
    show_more_filters_button: "1",
    more_filters_button_text: "More filters",
    more_filters_offcanvas_title: "More filters",
    archive_base_url: "/en/boats/",
  });

  assert.deepEqual(searchParams(url), { md_page: "1", md_featured: "1" });
});

test("removes UI configuration that an earlier version left in the address bar", () => {
  const polluted =
    PAGE +
    "?utm_source=mail&md_page=2&filters_ui_fields=term&filters_ui_fields_offcanvas=term,min_price&show_more_filters_button=1";

  const url = buildBrowserUrl(polluted, { md_page: "1", md_boat_cabins: "2" });

  assert.deepEqual(searchParams(url), { utm_source: "mail", md_page: "1", md_boat_cabins: "2" });
});

test("drops previous md_ filters that are no longer selected", () => {
  const url = buildBrowserUrl(PAGE + "?md_boat_cabins=3&md_term=ibiza", { md_page: "1", md_term: "ibiza" });

  assert.deepEqual(searchParams(url), { md_page: "1", md_term: "ibiza" });
});

test("skips request-only parameters and empty values", () => {
  const url = buildBrowserUrl(PAGE, {
    md_page: "1",
    md_lang: "es",
    id_group: "boats",
    limit_services: "12",
    offset_services: "0",
    card: "clean-grid",
    image_token: "image_main",
    date_picker_mode: "range",
    builders_options: "api",
    archive_scope: "v1.eyJib2F0X3R5cGVfaWQiOiI0In0." + "a".repeat(64),
    md_term: "   ",
    md_boat_skipper_option: "",
  });

  assert.deepEqual(searchParams(url), { md_page: "1" });
});

test("keeps repeated values of the same filter", () => {
  const url = buildBrowserUrl(PAGE, { md_tags: ["3", "", "7"] });

  assert.deepEqual(new URL(url).searchParams.getAll("md_tags"), ["3", "7"]);
});

test("recognises every UI configuration parameter", () => {
  ARCHIVE_UI_CONFIG_PARAMS.forEach((name) => assert.equal(isArchiveUiConfigParam(name), true, name));
  assert.equal(isArchiveUiConfigParam(" filters_ui_fields "), true);
  assert.equal(isArchiveUiConfigParam("md_page"), false);
  assert.equal(isArchiveUiConfigParam("card"), false);
  assert.equal(isArchiveUiConfigParam(undefined), false);
});

test("moves a stepper one step and keeps it inside its limits", () => {
  assert.equal(stepStepperValue(0, 1, 0, 10), 1);
  assert.equal(stepStepperValue("4", -1, "0", "10"), 3);
  assert.equal(stepStepperValue(0, -1, 0, 10), 0);
  assert.equal(stepStepperValue(10, 1, 0, 10), 10);
  assert.equal(stepStepperValue("", 1, 0, 10), 1);
});

test("maps the skipper choices to boat_skipper_option codes", () => {
  assert.equal(buildSkipperOptionValue(true, false, "0,2", "1,2"), "0,2");
  assert.equal(buildSkipperOptionValue(false, true, "0,2", "1,2"), "1,2");
  assert.equal(buildSkipperOptionValue(true, true, "0,2", "1,2"), "");
  assert.equal(buildSkipperOptionValue(false, false, "0,2", "1,2"), "");
  assert.equal(buildSkipperOptionValue(true, false, undefined, undefined), "");
});
