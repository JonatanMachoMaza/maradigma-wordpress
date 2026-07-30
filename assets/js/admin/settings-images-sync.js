(function () {
  'use strict';

  const cfg = window.MaradigmaImageSyncAdmin || {};

  function qs(panel, name) {
    return panel.querySelector(`[data-md-image-sync-field="${name}"]`);
  }

  function setText(panel, name, value) {
    const el = qs(panel, name);
    if (!el) return;

    const text = value === null || typeof value === 'undefined' ? '' : String(value);
    el.textContent = text;

    if (el.classList && el.classList.contains('description')) {
      el.style.display = text ? '' : 'none';
    }
  }

  function toggleCurrentRows(panel, show) {
    panel.querySelectorAll('[data-md-image-sync-current-row="1"]').forEach((row) => {
      row.style.display = show ? '' : 'none';
    });
  }

  function translateStatus(status) {
    const key = String(status || '').toLowerCase();
    return (cfg.i18n && cfg.i18n.status && cfg.i18n.status[key]) || status || '';
  }

  function translateWorker(label) {
    const key = String(label || '').toLowerCase();
    return (cfg.i18n && cfg.i18n.worker && cfg.i18n.worker[key]) || label || '';
  }

  function translateAsync(label) {
    const key = String(label || '').toLowerCase();
    return (cfg.i18n && cfg.i18n.async && cfg.i18n.async[key]) || label || '';
  }

  function formatRunCounts(state) {
    const template =
      (cfg.i18n && cfg.i18n.runCounts) || 'imported %1$d | reused %2$d | failed %3$d';

    return template
      .replace('%1$d', String(Number(state.images_imported || 0)))
      .replace('%2$d', String(Number(state.images_reused || 0)))
      .replace('%3$d', String(Number(state.images_failed || 0)));
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
    if (!payload || !payload.state || !payload.stats) return;

    const state = payload.state;
    const stats = payload.stats;
    const total = Number(state.boats_total || 0);
    const done = Number(state.boats_processed || 0);
    const currentImages = Number(state.current_boat_images_count || 0);
    const currentIndex = Number(state.current_image_index || 0);
    const hasCurrentBoat =
      String(state.status || '') === 'running' && String(state.current_boat_id || '') !== '' && currentImages > 0;

    panel.setAttribute('data-maradigma-image-sync-status', String(state.status || ''));

    setText(panel, 'cached_boats', stats.boats || 0);
    setText(panel, 'cached_images', stats.attachments || 0);
    setText(panel, 'status', translateStatus(state.status));
    setText(panel, 'last_run', payload.last_run_label || '');
    setText(panel, 'next_cron', payload.next_cron_label || '');
    setText(panel, 'worker_state', translateWorker(payload.worker_label));
    setText(panel, 'async_kick', translateAsync(payload.async_kick_label));
    setText(panel, 'progress', `${done} / ${total > 0 ? total : '?'}`);
    toggleCurrentRows(panel, hasCurrentBoat);
    setText(panel, 'current_boat', hasCurrentBoat ? state.current_boat_id : '');
    setText(panel, 'current_images', hasCurrentBoat ? `${currentIndex} / ${currentImages}` : '');
    setText(panel, 'run_counts', formatRunCounts(state));
    setText(panel, 'message', state.message || '');
    setText(panel, 'async_error', payload.async_kick_error || '');
  }

  function start(panel) {
    if (!cfg.ajaxUrl || !cfg.nonce || !cfg.statusAction || !cfg.pumpAction) return;

    const initialStatus = String(panel.getAttribute('data-maradigma-image-sync-status') || '').toLowerCase();
    if (initialStatus && initialStatus !== 'running') {
      return;
    }

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
        if (action && action.value === 'maradigma_images_sync_stop') {
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
    document.querySelectorAll('[data-maradigma-image-sync-panel="1"]').forEach(start);
  });
})();
