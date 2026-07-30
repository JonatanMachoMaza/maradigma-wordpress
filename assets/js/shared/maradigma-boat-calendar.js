// assets/js/shared/maradigma-boat-calendar.js
(() => {
  'use strict';

  class MaradigmaBoatCalendar {
    /**
     * @param {HTMLElement} root
     */
    constructor(root) {
      this.root = root;
      this.cfg = this.#getCfg();
      this.abort = null;

      this.select = root.querySelector('[data-mdcal-select]');
      this.tableWrap = root.querySelector('[data-mdcal-table]');
      this.btnPrev = root.querySelector('[data-mdcal-prev]');
      this.btnNext = root.querySelector('[data-mdcal-next]');

      this.monthOptions = [];
      this.idx = 0;
      this.dayMap = null;

      // ✅ Locale Intl seguro (BCP-47)
      this.intlLocale = this.#getIntlLocale(this.cfg?.locale);
    }

    init() {
      if (!this.cfg || !this.select || !this.tableWrap) return false;

      this.#setupMonths();
      this.#bindEvents();
      this.#setLoading(true, this.cfg?.i18n?.loading || 'Loading…');

      this.#fetchCalendar()
        .then((data) => {
          this.#buildDayMapFromApi(data);
          this.#setLoading(false);
          this.#paint();
        })
        .catch(() => {
          this.#renderError(this.cfg?.i18n?.failed || 'Calendar not available.');
        });

      return true;
    }

    destroy() {
      if (this.abort) this.abort.abort();
      this.abort = null;
    }

    // ─────────────────────────────
    // Locale helpers (✅ FIX en_GB -> en-GB)
    // ─────────────────────────────

    /**
     * Normalizes WP locales like "en_GB" / "es_ES" to BCP-47 tags ("en-GB" / "es-ES").
     * Also supports already-valid tags like "en-GB".
     *
     * @param {string} raw
     * @returns {string}
     */
    #normalizeLocale(raw) {
      const s = String(raw || '').trim();
      if (!s) return 'en-US';

      // Convert "en_GB" -> "en-GB"
      const replaced = s.replace(/_/g, '-');

      // Normalize casing: language lower, region upper if present
      const parts = replaced.split('-').filter(Boolean);
      if (!parts.length) return 'en-US';

      const lang = String(parts[0] || '').toLowerCase();
      const region = parts.length >= 2 ? String(parts[1] || '').toUpperCase() : '';

      // Keep only 2 parts for Intl (good enough for WP locales)
      if (region) return `${lang}-${region}`;
      return lang;
    }

    /**
     * Returns a locale that is safe for Intl.DateTimeFormat.
     * If the browser can't use it, falls back to 'en-US'.
     *
     * @param {string} raw
     * @returns {string}
     */
    #getIntlLocale(raw) {
      const normalized = this.#normalizeLocale(raw);

      // Validate support (avoid RangeError)
      try {
        const supported = Intl.DateTimeFormat.supportedLocalesOf([normalized]);
        if (supported && supported.length) return supported[0];
      } catch (_) {
        // ignore
      }

      return 'en-US';
    }

    // ─────────────────────────────
    // Private helpers
    // ─────────────────────────────

    #getCfg() {
      const raw = this.root.getAttribute('data-mdcal');
      if (!raw) return null;
      try {
        return JSON.parse(raw);
      } catch (_) {
        return null;
      }
    }

    #setupMonths() {
      const months = Number(this.cfg.months || 12);

      const now = new Date();
      const baseStart = (this.cfg.startMonth === 'next')
        ? new Date(now.getFullYear(), now.getMonth() + 1, 1)
        : new Date(now.getFullYear(), now.getMonth(), 1);

      this.monthOptions = this.#buildMonthOptions(this.intlLocale, baseStart, months);

      this.select.innerHTML = this.monthOptions
        .map((o, i) => `<option value="${i}">${this.#escape(o.label)}</option>`)
        .join('');

      this.idx = 0;
      this.#updateNavButtons();
    }

    #bindEvents() {
      this.select.addEventListener('change', () => {
        const n = Number(this.select.value);
        if (!Number.isFinite(n)) return;
        this.idx = Math.max(0, Math.min(this.monthOptions.length - 1, n));
        this.#updateNavButtons();
        if (this.dayMap) this.#paint();
      });

      if (this.btnPrev) {
        this.btnPrev.addEventListener('click', () => {
          if (this.idx > 0) {
            this.idx--;
            this.#updateNavButtons();
            if (this.dayMap) this.#paint();
          }
        });
      }

      if (this.btnNext) {
        this.btnNext.addEventListener('click', () => {
          if (this.idx < this.monthOptions.length - 1) {
            this.idx++;
            this.#updateNavButtons();
            if (this.dayMap) this.#paint();
          }
        });
      }
    }

    #updateNavButtons() {
      if (this.btnPrev) this.btnPrev.disabled = this.idx <= 0;
      if (this.btnNext) this.btnNext.disabled = this.idx >= this.monthOptions.length - 1;
      this.select.value = String(this.idx);
    }

    #setLoading(on, text) {
      const loading = this.tableWrap.querySelector('[data-mdcal-loading]');
      if (!loading) return;

      loading.textContent = text || loading.textContent || '';
      loading.style.display = on ? 'block' : 'none';
    }

    #renderError(msg) {
      this.tableWrap.innerHTML = `<div class="mdcal__error">${this.#escape(msg || 'Error')}</div>`;
    }

    async #fetchCalendar() {
      const baseUrl = this.cfg?.rest?.url || '';
      if (!baseUrl) throw new Error('Missing REST URL');

      const url = this.#buildUrl(baseUrl, {
        boat: this.cfg.boat,
        months: this.cfg.months,
        start_month: this.cfg.startMonth,
        include_booking_status: this.cfg.includeBookingStatus,
        locale: this.cfg.locale,
      });

      this.abort = new AbortController();

      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        signal: this.abort.signal,
      });

      const json = await res.json().catch(() => null);
      if (!json || json.success !== true || !json.data) {
        throw new Error('Invalid response');
      }

      return json.data;
    }

#buildDayMapFromApi(data) {
  const optionStatuses = Array.isArray(this.cfg.optionStatuses)
    ? this.cfg.optionStatuses.map(Number)
    : [2];

  const ranges = this.#normalizeRanges(data.items || []);

  const windowStart = this.#startOfMonth(this.monthOptions[0].date);
  const lastMonth = this.monthOptions[this.monthOptions.length - 1].date;
  const windowEnd = new Date(lastMonth.getFullYear(), lastMonth.getMonth() + 1, 0);

  this.dayMap = this.#buildDayMap(ranges, windowStart, windowEnd, optionStatuses);
}

    #paint() {
      const monthDate = this.monthOptions[this.idx].date;
      this.#renderMonthTable(this.tableWrap, this.intlLocale, monthDate, this.dayMap || {});
    }

    // ───────────── date helpers ─────────────

    #toYmd(d) {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return `${y}-${m}-${day}`;
    }

    #ymdToDate(ymd) {
      const parts = String(ymd || '').split('-').map(Number);
      if (parts.length !== 3 || !parts[0] || !parts[1] || !parts[2]) return null;
      return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    #startOfMonth(d) {
      return new Date(d.getFullYear(), d.getMonth(), 1);
    }

    #addMonths(d, n) {
      return new Date(d.getFullYear(), d.getMonth() + n, 1);
    }

    #buildMonthOptions(locale, startDate, months) {
      let fmt;
      try {
        fmt = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' });
      } catch (_) {
        fmt = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' });
      }

      const out = [];
      const total = Number.isFinite(months) ? months : 12;

      for (let i = 0; i < total; i++) {
        const md = this.#addMonths(startDate, i);
        out.push({ date: md, label: fmt.format(md) });
      }
      return out;
    }

    #weekdayLabelsMondayFirst(locale) {
      let fmt;
      try {
        fmt = new Intl.DateTimeFormat(locale, { weekday: 'short' });
      } catch (_) {
        fmt = new Intl.DateTimeFormat('en-US', { weekday: 'short' });
      }

      // pick a known date and shift to Monday
      const base = new Date(2024, 0, 4);
      const day = base.getDay();
      const deltaToMonday = (day === 0) ? -6 : (1 - day);
      const monday = new Date(base.getFullYear(), base.getMonth(), base.getDate() + deltaToMonday);

      const labels = [];
      for (let i = 0; i < 7; i++) {
        const d = new Date(monday.getFullYear(), monday.getMonth(), monday.getDate() + i);
        labels.push(fmt.format(d));
      }
      return labels;
    }

    // ───────────── data normalization ─────────────

    #normalizeRanges(items) {
      return (Array.isArray(items) ? items : [])
        .map(it => ({
          type: String(it.type || it.type_unavailability || ''),
          start: String(it.date_start || ''),
          end: String(it.date_end || ''),
          status: (it.status === null || typeof it.status === 'undefined') ? null : Number(it.status),
        }))
        .filter(r => r.start && r.end && (r.type === 'booking' || r.type === 'unavailability' || r.type === 'ical'))
        .map(r => {
          const ds = this.#ymdToDate(r.start);
          const de = this.#ymdToDate(r.end);
          if (!ds || !de) return null;

          const startTs = new Date(ds.getFullYear(), ds.getMonth(), ds.getDate()).getTime();
          const endTs = new Date(de.getFullYear(), de.getMonth(), de.getDate()).getTime();

          return { ...r, startTs, endTs };
        })
        .filter(Boolean);
    }

#buildDayMap(ranges, windowStart, windowEnd, optionStatuses) {
  const map = Object.create(null);
  const startTs = windowStart.getTime();
  const endTs = windowEnd.getTime();

  for (const r of ranges) {
    const rs = Math.max(r.startTs, startTs);
    const re = Math.min(r.endTs, endTs);
    if (re < rs) continue;

    let cur = new Date(rs);
    cur = new Date(cur.getFullYear(), cur.getMonth(), cur.getDate());

    const last = new Date(re);
    const lastDay = new Date(last.getFullYear(), last.getMonth(), last.getDate()).getTime();

    while (cur.getTime() <= lastDay) {
      const key = this.#toYmd(cur);

      let state = 'booked';
      if (r.type === 'booking') {
        const st = r.status;
        if (st !== null && optionStatuses.includes(st)) state = 'option';
        else state = 'booked';
      } else {
        state = 'booked';
      }

      if (!map[key]) map[key] = state;
      else if (map[key] === 'option' && state === 'booked') map[key] = 'booked';

      cur = new Date(cur.getFullYear(), cur.getMonth(), cur.getDate() + 1);
    }
  }

  return map;
}

    // ───────────── rendering ─────────────

    #renderMonthTable(container, locale, monthDate, dayMap) {
      const labels = this.#weekdayLabelsMondayFirst(locale);

      const year = monthDate.getFullYear();
      const month = monthDate.getMonth();

      const first = new Date(year, month, 1);
      const last = new Date(year, month + 1, 0);

      const firstDow = first.getDay();
      const offset = (firstDow === 0) ? 6 : (firstDow - 1);

      const totalDays = last.getDate();

      let html = '<table class="mdcal__table"><thead><tr>';
      for (const l of labels) html += `<th>${this.#escape(l)}</th>`;
      html += '</tr></thead><tbody><tr>';

      for (let i = 0; i < offset; i++) html += '<td class="mdcal__empty"></td>';

      let col = offset;

      for (let day = 1; day <= totalDays; day++) {
        const d = new Date(year, month, day);
        const key = this.#toYmd(d);

        const state = dayMap[key] || 'available';

        let cls = 'mdcal__day';
        if (state === 'booked') cls += ' mdcal__day--booked';
        if (state === 'option') cls += ' mdcal__day--option';

        html += `<td class="${cls}" data-date="${key}">${day}</td>`;
        col++;

        if (col === 7 && day !== totalDays) {
          html += '</tr><tr>';
          col = 0;
        }
      }

      if (col !== 0) {
        for (let i = col; i < 7; i++) html += '<td class="mdcal__empty"></td>';
      }

      html += '</tr></tbody></table>';
      container.innerHTML = html;
    }

    // ───────────── misc ─────────────

    #escape(str) {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    #buildUrl(base, params) {
      // base should be absolute (rest_url returns absolute). Still safe:
      const url = new URL(base, window.location.origin);

      Object.keys(params || {}).forEach((k) => {
        const v = params[k];
        if (v === null || typeof v === 'undefined' || v === '') return;
        url.searchParams.set(k, String(v));
      });

      return url.toString();
    }
  }

  // Boot loader (multiple instances + avoid double init)
  function boot() {
    document.querySelectorAll('.md-boat-calendar[data-mdcal]').forEach((root) => {
      if (root.getAttribute('data-mdcal-initialized') === '1') return;

      const instance = new MaradigmaBoatCalendar(root);
      const initialized = instance.init();
      if (!initialized) return;

      root.setAttribute('data-mdcal-initialized', '1');

      // Optional debug ref
      root.__mdcal = instance;
    });
  }

  function scheduleBoot() {
    window.clearTimeout(scheduleBoot.timer);
    scheduleBoot.timer = window.setTimeout(boot, 50);
  }
  scheduleBoot.timer = null;

  function nodeHasCalendar(node) {
    if (!node || node.nodeType !== 1) return false;
    if (node.matches && node.matches('.md-boat-calendar[data-mdcal]')) return true;
    return Boolean(node.querySelector && node.querySelector('.md-boat-calendar[data-mdcal]'));
  }

  function observeDynamicCalendars() {
    if (!('MutationObserver' in window)) return;

    const target = document.body || document.documentElement;
    if (!target) return;

    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        for (const node of mutation.addedNodes || []) {
          if (nodeHasCalendar(node)) {
            scheduleBoot();
            return;
          }
        }
      }
    });

    observer.observe(target, { childList: true, subtree: true });
  }

  function bindElementorHooks() {
    if (!window.elementorFrontend || !window.elementorFrontend.hooks) return false;

    window.elementorFrontend.hooks.addAction('frontend/element_ready/global', boot);
    window.elementorFrontend.hooks.addAction('frontend/element_ready/maradigma_boat_calendar.default', boot);

    return true;
  }

  function waitForElementorHooks() {
    if (bindElementorHooks()) return;

    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts++;
      if (bindElementorHooks() || attempts >= 20) {
        window.clearInterval(timer);
      }
    }, 250);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      boot();
      observeDynamicCalendars();
      waitForElementorHooks();
    });
  } else {
    boot();
    observeDynamicCalendars();
    waitForElementorHooks();
  }

  // Expose for optional external re-init (Elementor dynamic render hooks, etc.)
  window.MaradigmaBoatCalendarBoot = window.MaradigmaBoatCalendarBoot || boot;
})();
