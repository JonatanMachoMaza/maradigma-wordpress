(function () {
  'use strict';

  const cfg = window.MaradigmaBoatSyncAdmin || {};

  function qs(panel, name) {
    return panel.querySelector(`[data-md-boat-sync-field="${name}"]`);
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

  function syncStopButtons(panel, status) {
    const isRunning = String(status || '').toLowerCase() === 'running';
    panel.querySelectorAll('[data-md-boat-sync-stop="1"]').forEach((button) => {
      button.style.display = isRunning ? '' : 'none';
    });
  }

  function render(panel, payload) {
    if (!payload || !payload.state) return;

    const state = payload.state;
    const created = Number(state.posts_created_total || 0);
    const updated = Number(state.posts_updated_total || 0);
    const totalBoats = Number(state.boats_total || 0);
    const doneBoats = Number(state.boats_done || 0);

    panel.setAttribute('data-maradigma-boat-sync-status', String(state.status || ''));
    syncStopButtons(panel, state.status);

    setText(panel, 'last_sync', payload.last_run_label || '');
    setText(panel, 'boats_synced', state.unique_boats || doneBoats || 0);
    setText(panel, 'pages_touched', created + updated);
    setText(panel, 'created', created);
    setText(panel, 'updated', updated);
    setText(panel, 'status', translateStatus(state.status));
    setText(panel, 'current_phase', state.current_phase || '-');
    setText(panel, 'progress', `${doneBoats} / ${totalBoats > 0 ? totalBoats : '?'}`);
    setText(panel, 'next_cron', payload.next_cron_label || '');
    setText(panel, 'message', state.message || '');
  }

  function start(panel) {
    if (!cfg.ajaxUrl || !cfg.nonce || !cfg.statusAction || !cfg.pumpAction) return;

    const initialStatus = String(panel.getAttribute('data-maradigma-boat-sync-status') || '').toLowerCase();
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
                schedule(800);
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

    panel.querySelectorAll('[data-md-boat-sync-stop="1"]').forEach((button) => {
      button.addEventListener('click', () => {
        if (!cfg.stopAction || inFlight) return;

        active = false;
        button.disabled = true;

        request(cfg.stopAction)
          .then((json) => {
            if (json && json.success && json.data) {
              render(panel, json.data);
            }
          })
          .finally(() => {
            button.disabled = false;
          });
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
    document.querySelectorAll('[data-maradigma-boat-sync-panel="1"]').forEach(start);
  });
})();
