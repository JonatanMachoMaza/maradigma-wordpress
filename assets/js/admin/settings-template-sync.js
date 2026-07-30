(function () {
  'use strict';

  const cfg = window.MaradigmaTemplateSyncAdmin || {};

  function qs(panel, name) {
    return panel.querySelector(`[data-md-template-sync-field="${name}"]`);
  }

  function setText(panel, name, value) {
    const el = qs(panel, name);
    if (!el) return;

    el.textContent = value === null || typeof value === 'undefined' ? '' : String(value);
  }

  function translateStatus(status) {
    const key = String(status || '').toLowerCase();
    return (cfg.i18n && cfg.i18n.status && cfg.i18n.status[key]) || status || '';
  }

  function request(action) {
    const body = new window.FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce || '');

    return window
      .fetch(cfg.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body,
      })
      .then((response) => response.json());
  }

  function render(panel, payload) {
    if (!payload || !payload.state) return;

    const state = payload.state;
    const total = Number(state.pages_total || state.boats_total || 0);
    const scanned = Number(state.pages_scanned || state.boats_scanned || 0);
    const updated = Number(state.pages_updated || state.boats_updated || 0);
    const skipped = Number(state.pages_skipped || state.boats_skipped || state.boats_skipped_custom || 0);
    const failed = Number(state.pages_failed || state.boats_failed || 0);
    const boatsTotal = Number(state.boats_total || 0);
    const languagesOnPages = Number(state.languages_on_pages || 0);
    const languageCodes = Array.isArray(state.language_codes) ? state.language_codes : [];
    const languagesLabel = languageCodes.length > 0
      ? `${languagesOnPages} (${languageCodes.map((code) => String(code).toUpperCase()).join(', ')})`
      : languagesOnPages;
    const status = String(state.status || 'idle');
    const stopForm = panel.querySelector('[data-md-template-sync-stop="1"]');

    panel.setAttribute('data-maradigma-template-sync-status', status);

    setText(panel, 'status', translateStatus(status));
    setText(panel, 'progress', `${scanned} / ${total > 0 ? total : '?'}`);
    setText(panel, 'boats_total', boatsTotal);
    setText(panel, 'languages_on_pages', languagesLabel);
    setText(panel, 'updated', updated);
    setText(panel, 'skipped', skipped);
    setText(panel, 'failed', failed);
    setText(panel, 'last_run', payload.last_run_label || '');
    setText(panel, 'next_cron', payload.next_cron_label || '');
    setText(panel, 'message', state.message || '');
    setText(panel, 'skip_reasons', formatSkipReasons(state.skip_reasons));

    if (stopForm) {
      stopForm.style.display = status === 'running' ? 'inline-flex' : 'none';
    }
  }

  function formatSkipReasons(reasons) {
    if (!reasons || typeof reasons !== 'object') return '';

    return Object.keys(reasons)
      .sort()
      .map((key) => `${key}: ${Number(reasons[key] || 0)}`)
      .join(' | ');
  }

  function start(panel) {
    if (!cfg.ajaxUrl || !cfg.nonce || !cfg.statusAction || !cfg.pumpAction) return;

    let active = true;
    let inFlight = false;

    function schedule(delay) {
      if (!active) return;
      window.setTimeout(loop, delay);
    }

    function loop() {
      if (!active || inFlight) return;

      inFlight = true;

      request(cfg.statusAction)
        .then((json) => {
          if (!json || !json.success || !json.data) {
            schedule(5000);
            return null;
          }

          render(panel, json.data);

          const status = String((json.data.state && json.data.state.status) || '');
          if (status !== 'running') {
            active = false;
            return null;
          }

          return request(cfg.pumpAction).then((pumpJson) => {
            if (pumpJson && pumpJson.success && pumpJson.data) {
              render(panel, pumpJson.data);
              const pumpStatus = String((pumpJson.data.state && pumpJson.data.state.status) || '');
              if (pumpStatus === 'running') {
                schedule(700);
              } else {
                active = false;
              }
              return pumpJson;
            }

            schedule(5000);
            return pumpJson;
          });
        })
        .catch(() => {
          schedule(6000);
        })
        .finally(() => {
          inFlight = false;
        });
    }

    document.addEventListener('visibilitychange', () => {
      active = !document.hidden;
      if (active) {
        loop();
      }
    });

    panel.querySelectorAll('form').forEach((form) => {
      form.addEventListener('submit', () => {
        const action = form.querySelector('input[name="action"]');
        if (action && action.value === 'maradigma_force_template_sync_stop') {
          active = false;
        }
      });
    });

    request(cfg.statusAction)
      .then((json) => {
        if (json && json.success && json.data) {
          render(panel, json.data);
          const status = String((json.data.state && json.data.state.status) || '');
          if (status === 'running') {
            schedule(500);
          } else {
            active = false;
          }
        }
      })
      .catch(() => {});
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-maradigma-template-sync-panel="1"]').forEach(start);
  });
})();
