/**
 * MaradigmaApiClient
 * Shared fetch wrapper: headers (X-WP-Nonce), timeout, JSON parsing, errors.
 */
class MaradigmaApiClient {
  constructor(config = {}) {
    const g = window.MaradigmaConfig || {};

    this.nonce = String(config.nonce ?? g.nonce ?? "").trim();
    this.bookingNonce = String(config.bookingNonce ?? g.bookingNonce ?? "").trim();
    this.bookingNonceUrl = String(config.bookingNonceUrl ?? g.restUrlBookingNonce ?? "").trim();
    this.bookingNonceRefreshPromise = null;
    this.bookingSession = this._getOrCreateBookingSession();
    this.bookingIdempotencyKeys = new Map();
    this.timeoutMs = this._toInt(config.timeoutMs ?? g.timeoutMs ?? 15000, 15000);
    this.wpJsonBase = String(config.wpJsonBase ?? g.wpJsonBase ?? "/wp-json").trim() || "/wp-json";

    // ✅ default query params (global), e.g. { lang:"EN", expand:["additional_services"] }
    this.defaultQuery = (config.defaultQuery && typeof config.defaultQuery === "object")
      ? config.defaultQuery
      : (g.defaultQuery && typeof g.defaultQuery === "object" ? g.defaultQuery : {});
  }

  joinUrl(base, path) {
    const b = String(base || "");
    const p = String(path || "");
    if (!b) return p;
    if (!p) return b;
    if (b.endsWith("/") && p.startsWith("/")) return b + p.slice(1);
    if (!b.endsWith("/") && !p.startsWith("/")) return b + "/" + p;
    return b + p;
  }

  /**
   * ✅ Build a URL under wpJsonBase with query params.
   * - path can be "/maradigma/v1/boats/304"
   * - query supports primitives, arrays (expand), and objects (JSON stringified)
   *
   * Examples:
   *  this.buildUrl("/maradigma/v1/boats/304", { lang:"EN", expand:["prices","additional_services"] })
   */
  buildUrl(path, query = null) {
    const url = this.joinUrl(this.wpJsonBase, path);

    const merged = this._mergeQuery(this.defaultQuery, query);
    const qs = this._toQueryString(merged);

    if (!qs) return url;
    return url.includes("?") ? (url + "&" + qs) : (url + "?" + qs);
  }

  /**
   * ✅ Shortcut: GET by wp-json path + query
   */
  async get(path, query = null, options = {}) {
    const url = this.buildUrl(path, query);
    return this.getJson(url, options);
  }

  async getJson(url, options = {}) {
    const { nonce, headers, signal, timeoutMs, cache } = options;
    const finalNonce = this._resolveNonceForUrl(url, nonce, options);

    const { ctrl, finalSignal, cancel, didTimeout } = this._buildTimeoutSignal(signal, timeoutMs);
    try {
      const res = await fetch(url, {
        method: "GET",
        headers: this._buildHeaders(finalNonce, headers),
        credentials: "same-origin",
        signal: finalSignal,
        cache: cache || "default"
      });

      const text = await res.text();
      const data = this._parseJsonSafe(text);

      if (!res.ok) {
        if (finalNonce && this._canRetryWithoutNonce(options) && this._isCookieNonceError(data)) {
          return this.getJson(url, { ...options, nonce: "", retryWithoutNonce: false });
        }

        throw this._buildHttpError(res, data);
      }

      return data;
    } catch (error) {
      throw this._normalizeRequestError(error, didTimeout());
    } finally {
      cancel();
      void ctrl;
    }
  }

  async postJson(url, payload, options = {}) {
    const { nonce, headers, signal, timeoutMs } = options;
    const finalNonce = this._resolveNonceForUrl(url, nonce, options);

    const finalHeaders = this._buildHeaders(finalNonce, headers);
    finalHeaders["Content-Type"] = "application/json";

    if (this.bookingNonce && this._isBookingWriteUrl(url)) {
      finalHeaders["X-Maradigma-Booking-Nonce"] = this.bookingNonce;
    }
    if (this.bookingSession && this._isBookingWriteUrl(url)) {
      finalHeaders["X-Maradigma-Booking-Session"] = this.bookingSession;
    }
    const idempotencyScope = this._bookingIdempotencyScope(url, payload);
    if (idempotencyScope) {
      finalHeaders["Idempotency-Key"] = this._getIdempotencyKey(idempotencyScope);
    }

    const { ctrl, finalSignal, cancel, didTimeout } = this._buildTimeoutSignal(signal, timeoutMs);
    try {
      const res = await fetch(url, {
        method: "POST",
        headers: finalHeaders,
        credentials: "same-origin",
        body: JSON.stringify(payload || {}),
        signal: finalSignal
      });

      const text = await res.text();
      const data = this._parseJsonSafe(text);

      if (!res.ok) {
        if (
          this._isBookingWriteUrl(url)
          && this._isBookingNonceError(data)
          && options.retryBookingNonce !== false
        ) {
          const refreshed = await this._refreshBookingNonce();
          if (refreshed) {
            return this.postJson(url, payload, { ...options, retryBookingNonce: false });
          }
        }

        if (finalNonce && this._canRetryWithoutNonce(options) && this._isCookieNonceError(data)) {
          return this.postJson(url, payload, { ...options, nonce: "", retryWithoutNonce: false });
        }

        throw this._buildHttpError(res, data);
      }

      return data;
    } catch (error) {
      throw this._normalizeRequestError(error, didTimeout());
    } finally {
      cancel();
      void ctrl;
    }
  }

  // ─────────────────────────────────────────────
  // Internals
  // ─────────────────────────────────────────────
  _buildHeaders(nonce, extra) {
    const h = {};
    if (nonce) h["X-WP-Nonce"] = nonce;

    if (extra && typeof extra === "object") {
      Object.keys(extra).forEach((k) => { h[k] = extra[k]; });
    }
    return h;
  }

  _resolveNonceForUrl(url, nonceOption, options = {}) {
    if (this._isPublicMaradigmaRestUrl(url) && options.forceNonce !== true) {
      return "";
    }

    if (nonceOption !== undefined) {
      return String(nonceOption || "").trim();
    }

    return this.nonce;
  }

  _isPublicMaradigmaRestUrl(url) {
    let pathname = "";
    let restRoute = "";

    try {
      const base = (window && window.location && window.location.href) ? window.location.href : undefined;
      const parsed = new URL(String(url || ""), base);
      pathname = parsed.pathname;
      restRoute = parsed.searchParams.get("rest_route") || "";
    } catch {
      const raw = String(url || "");
      pathname = raw.split("?")[0] || "";

      const query = raw.split("?")[1] || "";
      query.split("&").some((part) => {
        const bits = part.split("=");
        if (decodeURIComponent(bits[0] || "") !== "rest_route") return false;

        restRoute = decodeURIComponent(bits.slice(1).join("=") || "");
        return true;
      });
    }

    pathname = decodeURIComponent(pathname).replace(/\/+$/, "");
    restRoute = decodeURIComponent(restRoute).replace(/\/+$/, "");

    if (this._isPublicMaradigmaRestPath(restRoute)) {
      return true;
    }

    return this._isPublicMaradigmaRestPath(pathname);
  }

  _isPublicMaradigmaRestPath(pathname) {
    return /(?:^|\/)(?:wp-json\/)?maradigma\/v1\/(?:countries|quote|boats-archive|boats\/\d+|boat|boat\/price-on-booking|calendar|booking|booking\/online|booking\/security-token|shop-cart\/[0-9a-zA-Z_-]+|booking\/rental-terms(?:\/[^/]+)?)$/.test(String(pathname || ""));
  }

  _isBookingWriteUrl(url) {
    try {
      const base = window?.location?.href || undefined;
      const parsed = new URL(String(url || ""), base);

      if (window?.location?.origin && parsed.origin !== window.location.origin) {
        return false;
      }

      const pathname = decodeURIComponent(parsed.pathname || "").replace(/\/+$/, "");
      const restRoute = decodeURIComponent(parsed.searchParams.get("rest_route") || "").replace(/\/+$/, "");
      const pattern = /(?:^|\/)(?:wp-json\/)?maradigma\/v1\/booking(?:\/online)?$/;

      return pattern.test(pathname) || pattern.test(restRoute);
    } catch {
      return false;
    }
  }

  _isBookingNonceError(data) {
    const code = String(data?.code || data?.error?.code || "").trim();
    return code === "maradigma_booking_invalid_nonce";
  }

  async _refreshBookingNonce() {
    if (this.bookingNonceRefreshPromise) {
      return this.bookingNonceRefreshPromise;
    }

    this.bookingNonceRefreshPromise = (async () => {
      const endpoint = this.bookingNonceUrl
        || this.joinUrl(this.wpJsonBase, "maradigma/v1/booking/security-token");
      const separator = endpoint.includes("?") ? "&" : "?";
      const refreshUrl = endpoint + separator + "_=" + Date.now();
      const { ctrl, finalSignal, cancel } = this._buildTimeoutSignal(undefined, this.timeoutMs);

      try {
        const res = await fetch(refreshUrl, {
          method: "GET",
          headers: { "Accept": "application/json" },
          credentials: "same-origin",
          cache: "no-store",
          signal: finalSignal
        });
        const text = await res.text();
        const data = this._parseJsonSafe(text);
        const nonce = String(data?.data?.nonce || data?.nonce || "").trim();

        if (!res.ok || !nonce) {
          return false;
        }

        this.bookingNonce = nonce;
        if (window.MaradigmaConfig && typeof window.MaradigmaConfig === "object") {
          window.MaradigmaConfig.bookingNonce = nonce;
        }

        return true;
      } catch {
        return false;
      } finally {
        cancel();
        void ctrl;
      }
    })();

    try {
      return await this.bookingNonceRefreshPromise;
    } finally {
      this.bookingNonceRefreshPromise = null;
    }
  }

  _getOrCreateBookingSession() {
    const storageKey = "maradigma_booking_session_v1";

    try {
      const stored = String(window.localStorage.getItem(storageKey) || "").trim();
      if (/^[A-Za-z0-9_-]{20,128}$/.test(stored)) {
        return stored;
      }

      const created = this._randomToken();
      window.localStorage.setItem(storageKey, created);
      return created;
    } catch {
      return this._randomToken();
    }
  }

  _randomToken() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID().replace(/-/g, "");
    }

    if (window.crypto && typeof window.crypto.getRandomValues === "function") {
      const bytes = new Uint8Array(24);
      window.crypto.getRandomValues(bytes);
      return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
    }

    return [
      Date.now().toString(36),
      Math.random().toString(36).slice(2),
      Math.random().toString(36).slice(2)
    ].join("");
  }

  _bookingIdempotencyScope(url, payload) {
    if (!this._isBookingWriteUrl(url)) {
      return "";
    }

    const step = Number(payload?.step || 0);
    const isLegacyBooking = step === 0;
    const isPaymentStep = step >= 3;
    if (!isLegacyBooking && !isPaymentStep) {
      return "";
    }

    return String(url || "") + "|" + JSON.stringify(payload || {});
  }

  _getIdempotencyKey(scope) {
    if (!this.bookingIdempotencyKeys.has(scope)) {
      this.bookingIdempotencyKeys.set(scope, this._randomToken());
    }

    return this.bookingIdempotencyKeys.get(scope);
  }

  _canRetryWithoutNonce(options) {
    return !options || options.retryWithoutNonce !== false;
  }

  _isCookieNonceError(data) {
    const code = String(data?.code || data?.error?.code || "").trim();
    const message = String(data?.message || data?.error?.message || "").toLowerCase();

    return code === "rest_cookie_invalid_nonce" ||
      message.includes("cookie check failed") ||
      message.includes("comprobaci") && message.includes("cookie");
  }

  _parseJsonSafe(text) {
    try { return JSON.parse(text); }
    catch { return { ok: false, raw: text }; }
  }

  _buildHttpError(res, data) {
    const msg =
      (data && data.error && data.error.message) ? data.error.message :
      (data && data.message) ? data.message :
      (data && typeof data.error === "string") ? data.error :
      ("HTTP " + res.status);

    const err = new Error(msg);
    err.status = res.status;
    err.data = data;
    return err;
  }

  _buildTimeoutSignal(externalSignal, timeoutMs) {
    const ms = this._toInt(timeoutMs ?? this.timeoutMs, this.timeoutMs);

    if (typeof AbortController === "undefined") {
      return {
        ctrl: null,
        finalSignal: externalSignal,
        cancel: () => {},
        didTimeout: () => false
      };
    }

    const ctrl = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
      timedOut = true;
      try { ctrl.abort(); } catch {}
    }, ms);

    if (externalSignal && typeof externalSignal.addEventListener === "function") {
      externalSignal.addEventListener("abort", () => {
        try { ctrl.abort(); } catch {}
      }, { once: true });
    }

    return {
      ctrl,
      finalSignal: ctrl.signal,
      cancel: () => { try { clearTimeout(timer); } catch {} },
      didTimeout: () => timedOut
    };
  }

  _normalizeRequestError(error, didTimeout) {
    if (!didTimeout) {
      return error;
    }

    const timeoutError = new Error("Request timed out.");
    timeoutError.name = "TimeoutError";
    timeoutError.code = "maradigma_request_timeout";
    timeoutError.cause = error;
    return timeoutError;
  }

  _toInt(v, def) {
    const n = parseInt(String(v ?? "").trim(), 10);
    return Number.isNaN(n) ? def : n;
  }

  // ✅ merge query objects shallow
  _mergeQuery(base, extra) {
    const b = (base && typeof base === "object") ? base : {};
    const e = (extra && typeof extra === "object") ? extra : {};
    const out = {};
    Object.keys(b).forEach((k) => { out[k] = b[k]; });
    Object.keys(e).forEach((k) => { out[k] = e[k]; });
    return out;
  }

  /**
   * ✅ Query string builder:
   * - arrays => repeated keys or CSV? We’ll use CSV for "expand" and "fields",
   *   repeated for others only if you want (here we do CSV for any array).
   * - booleans => 1/0
   * - null/"" => skipped
   */
  _toQueryString(query) {
    if (!query || typeof query !== "object") return "";

    const parts = [];

    Object.keys(query).forEach((key) => {
      const k = String(key || "").trim();
      if (!k) return;

      const v = query[key];

      if (v === undefined || v === null) return;

      // empty string -> skip
      if (typeof v === "string" && v.trim() === "") return;

      // boolean -> 1/0
      if (typeof v === "boolean") {
        parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(v ? "1" : "0"));
        return;
      }

      // array -> CSV
      if (Array.isArray(v)) {
        const items = v
          .map((x) => String(x ?? "").trim())
          .filter((x) => x !== "");
        if (!items.length) return;

        parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(items.join(",")));
        return;
      }

      // object -> JSON
      if (typeof v === "object") {
        try {
          const json = JSON.stringify(v);
          parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(json));
        } catch {
          // ignore non-serializable
        }
        return;
      }

      // number / string
      parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(String(v)));
    });

    return parts.join("&");
  }
}

window.MaradigmaApiClient = MaradigmaApiClient;
