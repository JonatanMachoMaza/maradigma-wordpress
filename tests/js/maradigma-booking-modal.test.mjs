import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import vm from "node:vm";

const source = await readFile(
  new URL("../../assets/js/frontend/maradigma-booking-modal.js", import.meta.url),
  "utf8"
);

function loadBookingModalClass() {
  const document = {
    readyState: "loading",
    addEventListener() {},
  };
  const window = {
    MaradigmaConfig: {},
  };
  const context = vm.createContext({
    console,
    document,
    window,
  });

  vm.runInContext(source, context);

  return window.MaradigmaBookingModal;
}

test("toggles rental terms without rerendering the step or resetting its scroll", async () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const toggleAttributes = new Map([["aria-expanded", "false"]]);
  const contentAttributes = new Map([
    ["data-md-terms-loaded", "0"],
    ["data-md-terms-loading", "0"],
  ]);
  let clickHandler = null;
  let rentalTermsRequests = 0;

  const toggle = {
    addEventListener(type, handler) {
      if (type === "click") clickHandler = handler;
    },
    setAttribute(name, value) {
      toggleAttributes.set(name, String(value));
    },
  };
  const termsContent = {
    innerHTML: "",
    getAttribute(name) {
      return contentAttributes.get(name) || null;
    },
    setAttribute(name, value) {
      contentAttributes.set(name, String(value));
    },
  };
  const termsPanel = {
    hidden: true,
    querySelector(selector) {
      return selector === '[data-md-terms-content="1"]' ? termsContent : null;
    },
  };
  const container = {
    querySelector(selector) {
      if (selector === '[data-md-action="toggle-terms"]') return toggle;
      if (selector === ".md-terms") return termsPanel;
      return null;
    },
  };
  const modal = Object.create(MaradigmaBookingModal.prototype);
  modal.state = { ui: { termsOpen: false } };
  modal._renderCurrentStep = () => {
    throw new Error("The terms toggle must not rerender the current step.");
  };
  modal._esc = (value) => String(value);
  modal._t = (_key, fallback) => fallback;
  modal._getRentalTerms = async () => {
    rentalTermsRequests += 1;
    return "<p>Rental terms</p>";
  };

  modal._bindStep3Events(container);
  assert.equal(typeof clickHandler, "function");

  await clickHandler({ preventDefault() {} });

  assert.equal(modal.state.ui.termsOpen, true);
  assert.equal(toggleAttributes.get("aria-expanded"), "true");
  assert.equal(termsPanel.hidden, false);
  assert.equal(termsContent.innerHTML, "<p>Rental terms</p>");
  assert.equal(contentAttributes.get("data-md-terms-loaded"), "1");
  assert.equal(rentalTermsRequests, 1);

  await clickHandler({ preventDefault() {} });

  assert.equal(modal.state.ui.termsOpen, false);
  assert.equal(toggleAttributes.get("aria-expanded"), "false");
  assert.equal(termsPanel.hidden, true);
  assert.equal(rentalTermsRequests, 1);
});

test("does not expose AbortSignal implementation messages to customers", () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);
  const loggedMessages = [];

  modal._t = (_key, fallback) => fallback;
  modal._logFrontendTechnicalError = (message) => loggedMessages.push(message);

  assert.equal(
    modal._toPublicErrorMessage("signal is aborted without reason"),
    "We cannot process your request right now. Please try again later."
  );
  assert.deepEqual(loggedMessages, ["signal is aborted without reason"]);
});

test("routes an invalid booking nonce to the explicit session-expired fallback", () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);
  let sessionExpiredCalls = 0;
  let genericErrorCalls = 0;

  modal._showBookingSessionExpiredAndScroll = () => {
    sessionExpiredCalls += 1;
  };
  modal._showErrorAndScroll = () => {
    genericErrorCalls += 1;
  };

  modal._showBookingRequestError({
    message: "The booking security token is invalid.",
    data: { code: "maradigma_booking_invalid_nonce" },
  });

  assert.equal(sessionExpiredCalls, 1);
  assert.equal(genericErrorCalls, 0);
});

test("removes an occupied half-day slot and does not offer full-day rental", () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);
  const rangeTimeslots = [
    {
      id: 506,
      start_time: "10:00:00",
      compatible_with_day_rental: true,
    },
    {
      id: 635,
      start_time: "15:00:00",
      compatible_with_day_rental: true,
    },
  ];

  modal.state = {
    boat_data: {
      unavailability_dates_and_timeslots: {
        dates: [],
        time_slots: [
          {
            date: "2026-08-28",
            time_start: "10:00",
            time_end: "14:00",
            id_time_slot: 506,
          },
        ],
      },
    },
  };

  const available = modal._getAvailableTimeSlotsForDate("2026-08-28", rangeTimeslots);

  assert.deepEqual(available.map((timeSlot) => timeSlot.id), [635]);
  assert.equal(modal._canBookFullDay(rangeTimeslots, available), false);
});

test("renders every half-day schedule as a visible card and disables occupied ones", () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);
  const rangeTimeslots = [
    { id: 506, name: "Midday", start_time: "10:00:00", duration: 240 },
    { id: 635, name: "Afternoon", start_time: "15:00:00", duration: 240 },
  ];

  modal.cfg = { boatId: "2410" };
  modal.state = {
    form: { date_start: "2026-08-28", timeslot_id: null },
    boat_data: {
      unavailability_dates_and_timeslots: {
        dates: [],
        time_slots: [{ date: "2026-08-28", id_time_slot: 506, time_start: "10:00", time_end: "14:00" }],
      },
    },
  };
  modal._esc = (value) => String(value);
  modal._t = (key, fallback) => (key === "ui.occupied" ? "Occupied" : fallback);
  modal._formatTimeslotRange = (startTime) => (String(startTime).startsWith("10:") ? "10:00 - 14:00" : "15:00 - 19:00");

  const html = modal._renderTimeslotSelect(rangeTimeslots);
  const occupiedCard = html.slice(html.indexOf('value="506"'), html.indexOf('value="635"'));
  const availableCard = html.slice(html.indexOf('value="635"'));

  assert.doesNotMatch(html, /<select\b/);
  assert.equal((html.match(/type="radio"/g) || []).length, 2);
  assert.match(occupiedCard, /disabled/);
  assert.match(occupiedCard, /Occupied/);
  assert.doesNotMatch(availableCard, /disabled/);
  assert.match(html, /md-timeslots__help/);
});

test("matches unavailable slots by normalized start time when identifiers differ", () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);

  assert.equal(
    modal._isTimeSlotBlocked(
      { id: 999, start_time: "10:00:00" },
      { id_time_slot: 506, time_start: "10:00", time_end: "14:00" }
    ),
    true
  );
});

test("recognizes an unavailable selected schedule in the price response", () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);

  assert.equal(
    modal._isQuoteUnavailable({
      status: "success",
      data: { availability: { is_available: false, status: "not_available" } },
    }),
    true
  );
  assert.equal(
    modal._isQuoteUnavailable({
      status: "success",
      data: { availability: { is_available: true, status: "available" } },
    }),
    false
  );
});

test("forces a no-store availability refresh when the modal opens", async () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);
  const apiCalls = [];

  modal.cfg = { boatId: "2410", lang: "es", expand: "service_unavailability_dates", restNonce: "nonce" };
  modal.wrapper = { setAttribute() {} };
  modal.state = { boat_data: null };
  modal._boatCache = {
    2410: { ts: Date.now(), data: { source: "stale" } },
  };
  modal._boatCacheTtlMs = 60 * 1000;
  modal._buildBoatEndpoint = () => "https://example.test/wp-json/maradigma/v1/boats/2410";
  modal._unwrapBoatResponse = (raw) => raw.data;
  modal._extractBoatCapacity = () => 0;
  modal.setLoading = () => {};
  modal.api = {
    async getJson(url, options) {
      apiCalls.push({ url, options });
      return { data: { source: "fresh" } };
    },
  };

  await modal._hydrateBoatOnOpen({ forceRefresh: true });

  assert.equal(apiCalls.length, 1);
  assert.equal(apiCalls[0].options.cache, "no-store");
  assert.equal(modal.state.boat_data.source, "fresh");
});

test("clears an unavailable selection, refreshes availability, and requotes", async () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);
  const unavailableQuote = {
    status: "success",
    data: {
      availability: { is_available: false },
      boat: { price_ranges: [{ timeslots: [{ id: 506 }, { id: 635 }] }] },
    },
  };
  let refreshOptions = null;
  let renderCalls = 0;
  let requoteCalls = 0;
  let shownMessage = "";

  modal.cfg = { boatId: "2410" };
  modal._boatCache = { 2410: { data: { source: "stale" }, ts: Date.now() } };
  modal.state = {
    currentStep: 1,
    form: { full_day: false, timeslot_id: 506, timeslot: { id: 506 } },
    quote: unavailableQuote,
  };
  modal._lastQuoteOk = unavailableQuote;
  modal._hydrateBoatOnOpen = async (options) => {
    refreshOptions = options;
  };
  modal.render = () => {
    renderCalls += 1;
  };
  modal._showErrorAndScroll = (message) => {
    shownMessage = message;
  };
  modal._t = (_key, fallback) => fallback;
  modal.schedulePriceOnBooking = () => {
    requoteCalls += 1;
  };

  await modal._handleUnavailableSelection(unavailableQuote);

  assert.equal(modal.state.form.full_day, false);
  assert.equal(modal.state.form.timeslot_id, null);
  assert.equal(modal.state.form.timeslot, null);
  assert.equal(modal.state.quote, unavailableQuote);
  assert.equal(modal._lastQuoteOk, null);
  assert.equal(modal._boatCache[2410], undefined);
  assert.equal(refreshOptions?.forceRefresh, true);
  assert.equal(renderCalls, 1);
  assert.equal(requoteCalls, 1);
  assert.match(shownMessage, /no longer available/i);
});

test("renders the payment step and posts no payment method until the API gives one", () => {
  const MaradigmaBookingModal = loadBookingModalClass();
  const modal = Object.create(MaradigmaBookingModal.prototype);

  modal.state = {
    api: {},
    ui: { termsOpen: false },
    form: { payment_method: "", people: 2, additionals_selected: {} },
  };
  modal.globalCfg = {};
  modal.cfg = { boatId: "2410" };
  modal._esc = (value) => String(value);
  modal._t = (_key, fallback) => fallback;
  modal._svg = () => "";
  modal._renderInlineStepTitle = () => "";
  modal._renderSummaryAside = () => "";

  // No payment method configured: the API applies the tenant's default.
  const withoutDefault = modal._renderStep3();
  assert.match(withoutDefault, /data-md-field="payment_method"/);
  assert.match(withoutDefault, /value=""/);
  assert.equal(modal.state.form.payment_method, "");

  modal.state.api.payment = { default_method: { key: "redsys", name: "Redsys", group: "credit-card" } };
  modal.state.form.payment_method = "";
  const withDefault = modal._renderStep3();
  assert.match(withDefault, /value="redsys"/);
  assert.equal(modal.state.form.payment_method, "redsys");
});
