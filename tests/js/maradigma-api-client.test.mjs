import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import vm from "node:vm";

const source = await readFile(
  new URL("../../assets/js/frontend/maradigma-api-client.js", import.meta.url),
  "utf8"
);

function response(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify(body),
  };
}

function createClient(fetchMock) {
  const storage = new Map();
  const window = {
    MaradigmaConfig: {
      bookingNonce: "stale-nonce",
      restUrlBookingNonce: "https://example.test/wp-json/maradigma/v1/booking/security-token",
      wpJsonBase: "https://example.test/wp-json/",
    },
    location: {
      href: "https://example.test/boats/test/",
      origin: "https://example.test",
    },
    localStorage: {
      getItem: (key) => storage.get(key) || null,
      setItem: (key, value) => storage.set(key, String(value)),
    },
    crypto: {
      randomUUID: () => "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
    },
  };

  const context = vm.createContext({
    AbortController,
    Date,
    JSON,
    Map,
    Math,
    String,
    URL,
    Uint8Array,
    clearTimeout,
    fetch: fetchMock,
    setTimeout,
    window,
  });

  vm.runInContext(source, context);

  return new window.MaradigmaApiClient();
}

test("refreshes an invalid booking nonce and retries once with the same idempotency key", async () => {
  const calls = [];
  const fetchMock = async (url, options) => {
    calls.push({ url: String(url), options });

    if (calls.length === 1) {
      return response(403, { code: "maradigma_booking_invalid_nonce" });
    }
    if (calls.length === 2) {
      return response(200, { success: true, data: { nonce: "fresh-nonce" } });
    }

    return response(200, { success: true });
  };
  const client = createClient(fetchMock);
  const bookingUrl = "https://example.test/wp-json/maradigma/v1/booking/online";

  const result = await client.postJson(bookingUrl, { step: 3, uuid_shop_cart: "cart-1" });

  assert.equal(result.success, true);
  assert.equal(calls.length, 3);
  assert.equal(calls[0].options.headers["X-Maradigma-Booking-Nonce"], "stale-nonce");
  assert.match(calls[1].url, /booking\/security-token\?_=/);
  assert.equal(calls[1].options.cache, "no-store");
  assert.equal(calls[2].options.headers["X-Maradigma-Booking-Nonce"], "fresh-nonce");
  assert.equal(
    calls[0].options.headers["Idempotency-Key"],
    calls[2].options.headers["Idempotency-Key"]
  );
});

test("does not loop when the retried booking nonce is rejected again", async () => {
  const calls = [];
  const fetchMock = async (url, options) => {
    calls.push({ url: String(url), options });

    if (calls.length === 2) {
      return response(200, { success: true, data: { nonce: "fresh-nonce" } });
    }

    return response(403, { code: "maradigma_booking_invalid_nonce" });
  };
  const client = createClient(fetchMock);

  await assert.rejects(
    client.postJson(
      "https://example.test/wp-json/maradigma/v1/booking/online",
      { step: 3, uuid_shop_cart: "cart-2" }
    ),
    (error) => error?.status === 403
  );

  assert.equal(calls.length, 3);
});

test("normalizes an internal request timeout without exposing the browser abort message", async () => {
  const fetchMock = async (_url, options) => new Promise((_resolve, reject) => {
    options.signal.addEventListener("abort", () => {
      const abortError = new Error("signal is aborted without reason");
      abortError.name = "AbortError";
      reject(abortError);
    }, { once: true });
  });
  const client = createClient(fetchMock);

  await assert.rejects(
    client.postJson(
      "https://example.test/wp-json/maradigma/v1/booking/online",
      { step: 3, uuid_shop_cart: "cart-timeout" },
      { timeoutMs: 1 }
    ),
    (error) => (
      error?.name === "TimeoutError" &&
      error?.code === "maradigma_request_timeout" &&
      error?.message === "Request timed out."
    )
  );
});
