/**
 * MaradigmaBoatUI
 * - Resuelve boatId desde data-boat-id o desde panel de Elementor (top window)
 * - Precarga datos del barco (opcional) y los guarda en wrapper.__mdBoatData
 * - Soporta query params (lang/expand) para pedir datos “enriquecidos” (precios, servicios adicionales, etc.)
 * - Expone helpers: getBoatData(), getAdditionalServices(), getPrices()
 * - Emite evento para abrir modal de booking: MaradigmaEvents.emit("booking:open", { boatId, boatData })
 *
 * Requisitos:
 * - MaradigmaApiClient debe incluir buildUrl() y/o get(path, query) (como te pasé antes).
 */
class MaradigmaBoatUI {
    constructor(wrapper, options = {}) {
        this.wrapper = wrapper;
        this.options = options;

        this.globalCfg = window.MaradigmaConfig || {};

        // ─────────────────────────────────────────────
        // API client
        // ─────────────────────────────────────────────
        this.api = new MaradigmaApiClient({
            nonce: "",
            timeoutMs: options.timeoutMs ?? this.globalCfg.timeoutMs ?? 15000,
            wpJsonBase: options.wpJsonBase ?? this.globalCfg.wpJsonBase ?? "/wp-json",
            // opcional: defaultQuery global
            defaultQuery: options.defaultQuery ?? this.globalCfg.defaultQuery ?? {}
        });

        // ─────────────────────────────────────────────
        // Boat identifier
        // ─────────────────────────────────────────────
        this.boatId = this._resolveBoatId();

        // ─────────────────────────────────────────────
        // Cache
        // ─────────────────────────────────────────────
        this.cacheTtlMs = this._toInt(options.cacheTtlMs ?? wrapper.getAttribute("data-cache-ttl-ms") ?? 60 * 1000, 60 * 1000);

        // Cache per instancia:
        // this._cache[cacheKey] = { ts:number, data:any }
        // cacheKey incluye boatId + lang + expand
        this._cache = {};

        // ─────────────────────────────────────────────
        // Default language + expand
        // ─────────────────────────────────────────────
        this.defaultLang = String(
            options.lang ??
            wrapper.getAttribute("data-lang") ??
            this.globalCfg.lang ??
            this.globalCfg.default_language ??
            "EN"
        ).trim() || "EN";

        const expandRaw =
            options.expand ??
            wrapper.getAttribute("data-expand") ??
            this.globalCfg.expand ??
            this.globalCfg.defaultExpand ??
            "";

        // Por defecto, si no te pasan nada, NO forzamos expand.
        // Si quieres que siempre pida precios + servicios, ponlo en data-expand o en options.
        this.defaultExpand = this._normalizeExpand(expandRaw);

        // Prefijos de expand “típicos” (por si luego quieres usar helpers)
        // Nota: no asumimos nombres exactos: esto es solo conveniencia.
        this.expandAdditionalServices = this._normalizeExpand(
            options.expandAdditionalServices ??
            wrapper.getAttribute("data-expand-additional-services") ??
            "additional_services"
        );
        this.expandPrices = this._normalizeExpand(
            options.expandPrices ??
            wrapper.getAttribute("data-expand-prices") ??
            "prices"
        );

        // Abort controller para cargas (si quieres cancelación externa)
        this._abort = null;
    }

    init() {
        if (!this.wrapper || this.wrapper.__mdBoatUiMounted) return;
        this.wrapper.__mdBoatUiMounted = true;

        this._bindOpenButtons();

        // Optional: hydrate/store boat data
        this.loadBoatDetails()
            .then((data) => {
                if (data && typeof data === "object") this.wrapper.__mdBoatData = data;
            })
            .catch(() => { });
    }

    destroy() {
        // Si en un futuro quieres desbind, aquí.
        this.wrapper.__mdBoatUiMounted = false;
        this._cancelPending();
    }

    getBoatId() {
        return this.boatId;
    }

    /**
     * Helper: devuelve el boatData cacheado en el wrapper si existe.
     */
    getBoatDataFromWrapper() {
        const d = this.wrapper && this.wrapper.__mdBoatData;
        return (d && typeof d === "object") ? d : null;
    }

    /**
     * Helper: carga el barco (con cache TTL) y devuelve el data (sin wrapper envelope).
     *
     * @param {object} params
     * @param {string} [params.lang]
     * @param {string|string[]|null} [params.expand] CSV o array
     * @param {AbortSignal|null} [params.signal]
     */
    async loadBoatDetails(params = {}) {
        const boatId = String(this.boatId || "").trim();
        if (!boatId) return null;

        const lang = String(params.lang ?? this.defaultLang).trim() || this.defaultLang;
        const expand = this._normalizeExpand(params.expand ?? this.defaultExpand);

        const cacheKey = this._buildCacheKey(boatId, lang, expand);

        const now = Date.now();
        const cached = this._cache[cacheKey];
        if (cached && (now - cached.ts) < this.cacheTtlMs) return cached.data;

        const url = this._buildBoatEndpoint(boatId, { lang, expand });

        const raw = await this.api.getJson(url, { signal: params.signal ?? null });
        const data = this._unwrapBoatResponse(raw);

        this._cache[cacheKey] = { ts: now, data };
        return data;
    }

    /**
     * Alias “bonito”
     */
    async getBoatData(params = {}) {
        const data = await this.loadBoatDetails(params);
        if (data && typeof data === "object") {
            this.wrapper.__mdBoatData = data;
        }
        return data;
    }

    /**
     * Devuelve servicios adicionales del boatData.
     * Si no están presentes, vuelve a pedir el barco con expand adicional_services (y respeta cache).
     *
     * @param {object} params
     * @param {string} [params.lang]
     * @param {AbortSignal|null} [params.signal]
     * @return {Promise<Array|null>}
     */
    async getAdditionalServices(params = {}) {
        const lang = String(params.lang ?? this.defaultLang).trim() || this.defaultLang;

        // 1) intenta desde wrapper.__mdBoatData
        const existing = this.getBoatDataFromWrapper();
        const fromExisting = this._extractAdditionalServices(existing);
        if (fromExisting) return fromExisting;

        // 2) pide con expand acumulado (default + additional_services)
        const expand = this._mergeExpand(this.defaultExpand, this.expandAdditionalServices);
        const data = await this.getBoatData({ lang, expand, signal: params.signal ?? null });

        return this._extractAdditionalServices(data);
    }

    /**
     * Devuelve precios del boatData.
     * Si no están presentes, vuelve a pedir el barco con expand prices (y respeta cache).
     *
     * @param {object} params
     * @param {string} [params.lang]
     * @param {AbortSignal|null} [params.signal]
     * @return {Promise<any|null>}
     */
    async getPrices(params = {}) {
        const lang = String(params.lang ?? this.defaultLang).trim() || this.defaultLang;

        // 1) intenta desde wrapper.__mdBoatData
        const existing = this.getBoatDataFromWrapper();
        const fromExisting = this._extractPrices(existing);
        if (fromExisting) return fromExisting;

        // 2) pide con expand acumulado (default + prices)
        const expand = this._mergeExpand(this.defaultExpand, this.expandPrices);
        const data = await this.getBoatData({ lang, expand, signal: params.signal ?? null });

        return this._extractPrices(data);
    }

    /**
     * Útil si quieres precargar “todo lo necesario para booking”:
     * - boatData con expand default + prices + additional_services
     */
    async preloadBookingData(params = {}) {
        const lang = String(params.lang ?? this.defaultLang).trim() || this.defaultLang;
        const expand = this._mergeExpand(this.defaultExpand, this.expandPrices, this.expandAdditionalServices);

        const data = await this.getBoatData({ lang, expand, signal: params.signal ?? null });
        return {
            boatData: data,
            prices: this._extractPrices(data),
            additionalServices: this._extractAdditionalServices(data)
        };
    }

    // ─────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────
    _bindOpenButtons() {
        const buttons = this.wrapper.querySelectorAll("[data-md-open-booking]");
        if (!buttons || !buttons.length) return;

        buttons.forEach((btn) => {
            btn.addEventListener("click", async (e) => {
                if (e && e.preventDefault) e.preventDefault();

                const boatIdResolved = this._toInt(this.boatId, 0) || this.boatId;

                // ✅ Intenta meter boatData “rico” en el evento (depósito/fuel/precios/servicios)
                let boatData = null;

                // 1) Si ya lo precargamos, úsalo
                if (this.wrapper.__mdBoatData && typeof this.wrapper.__mdBoatData === "object") {
                    boatData = this.wrapper.__mdBoatData;
                } else {
                    // 2) Si no, lo cargamos ahora (con cache TTL)
                    try {
                        // aquí puedes decidir si quieres expand “booking-ready”
                        const expand = this._mergeExpand(this.defaultExpand, this.expandPrices, this.expandAdditionalServices);
                        boatData = await this.loadBoatDetails({ expand });
                        if (boatData) this.wrapper.__mdBoatData = boatData;
                    } catch {
                        boatData = null;
                    }
                }

                MaradigmaEvents.emit("booking:open", {
                    boatId: boatIdResolved,
                    boatData: boatData,
                    source: "boat-ui"
                });
            });
        });
    }

    _resolveBoatId() {
        let id = String(this.options.boatId ?? this.wrapper.getAttribute("data-boat-id") ?? "").trim();
        if (id) return id;

        // Try from Elementor editor top panel
        id = this._readBoatIdFromTopPanel();
        if (id) {
            this.wrapper.setAttribute("data-boat-id", id);
            return id;
        }
        return "";
    }

    _readBoatIdFromTopPanel() {
        try {
            const topDoc = (window.top && window.top.document) ? window.top.document : null;
            if (!topDoc) return "";
            const el = topDoc.querySelector("#maradigma_cpt_boat_id");
            if (el && el.value) return String(el.value || "").trim();
        } catch { }
        return "";
    }

    _wpJsonBase() {
        const g = this.globalCfg;
        const base = String(g.wpJsonBase || "/wp-json").trim();
        return base || "/wp-json";
    }

    /**
     * Construye endpoint REST WP para el detalle del barco, con query params.
     * Espera que tu WP endpoint soporte:
     *  - ?lang=EN
     *  - ?expand=prices,additional_services
     */
    _buildBoatEndpoint(boatId, query = {}) {
        const wpJson = this._wpJsonBase();
        const base = this.api.joinUrl(wpJson, "maradigma/v1/boats/" + encodeURIComponent(String(boatId)));

        const qs = this._toQueryString(query);
        if (!qs) return base;
        return base.includes("?") ? (base + "&" + qs) : (base + "?" + qs);
    }

    _unwrapBoatResponse(raw) {
        if (!raw || typeof raw !== "object") return raw;
        if (raw.success === true && raw.data && typeof raw.data === "object") return raw.data;
        if (raw.data && typeof raw.data === "object") return raw.data;
        return raw;
    }

    _normalizeExpand(v) {
        if (Array.isArray(v)) {
            return v.map((x) => String(x ?? "").trim()).filter(Boolean);
        }
        const s = String(v ?? "").trim();
        if (!s) return [];
        return s.split(",").map((x) => x.trim()).filter(Boolean);
    }

    _mergeExpand(...lists) {
        const out = [];
        lists.forEach((lst) => {
            const arr = this._normalizeExpand(lst);
            arr.forEach((x) => out.push(x));
        });
        // unique
        return Array.from(new Set(out));
    }

    _buildCacheKey(boatId, lang, expandArr) {
        const exp = Array.isArray(expandArr) ? expandArr.slice().sort().join(",") : "";
        return String(boatId) + "::" + String(lang) + "::" + exp;
    }

    _toQueryString(query) {
        if (!query || typeof query !== "object") return "";

        const parts = [];
        Object.keys(query).forEach((key) => {
            const k = String(key || "").trim();
            if (!k) return;

            const v = query[key];
            if (v === undefined || v === null) return;
            if (typeof v === "string" && v.trim() === "") return;

            if (Array.isArray(v)) {
                const items = v.map((x) => String(x ?? "").trim()).filter(Boolean);
                if (!items.length) return;
                parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(items.join(",")));
                return;
            }

            if (typeof v === "boolean") {
                parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(v ? "1" : "0"));
                return;
            }

            if (typeof v === "object") {
                try {
                    parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(JSON.stringify(v)));
                } catch { }
                return;
            }

            parts.push(encodeURIComponent(k) + "=" + encodeURIComponent(String(v)));
        });

        return parts.join("&");
    }

    /**
     * Intentos razonables para extraer “additional services”.
     * Ajusta keys según tu API real.
     */
    _extractAdditionalServices(boatData) {
        if (!boatData || typeof boatData !== "object") return null;

        // Posibles nombres
        const candidates = [
            "additional_services",
            "additionalServices",
            "services_additional",
            "servicesAdditional",
            "extras",
            "extra_services"
        ];

        for (let i = 0; i < candidates.length; i++) {
            const k = candidates[i];
            if (Array.isArray(boatData[k])) return boatData[k];
            if (boatData[k] && typeof boatData[k] === "object") {
                // a veces viene {items:[...]}
                if (Array.isArray(boatData[k].items)) return boatData[k].items;
            }
        }

        return null;
    }

    /**
     * Intentos razonables para extraer “prices”.
     * Ajusta keys según tu API real.
     */
    _extractPrices(boatData) {
        if (!boatData || typeof boatData !== "object") return null;

        const candidates = [
            "prices",
            "price",
            "rates",
            "price_rates",
            "group_prices",
            "hous_group_prices"
        ];

        for (let i = 0; i < candidates.length; i++) {
            const k = candidates[i];
            if (boatData[k] !== undefined && boatData[k] !== null) return boatData[k];
        }

        return null;
    }

    _cancelPending() {
        if (this._abort && typeof this._abort.abort === "function") {
            try { this._abort.abort(); } catch { }
        }
        this._abort = null;
    }

    _toInt(v, def) {
        const n = parseInt(String(v ?? "").trim(), 10);
        return Number.isNaN(n) ? def : n;
    }

    // ─────────────────────────────────────────────
    // Static boot helper
    // ─────────────────────────────────────────────
    static boot(selector = "[data-md-boat-ui]") {
        const wrappers = document.querySelectorAll(selector);
        if (!wrappers || !wrappers.length) return;

        wrappers.forEach((wrapper) => {
            const ui = new MaradigmaBoatUI(wrapper);
            ui.init();
        });
    }
}

// Auto-boot
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => MaradigmaBoatUI.boot());
} else {
    MaradigmaBoatUI.boot();
}

window.MaradigmaBoatUI = MaradigmaBoatUI;
