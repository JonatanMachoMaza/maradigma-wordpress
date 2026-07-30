(function () {
  'use strict';

  const TAB_NAME = 'maradigma-tab';
  const PANEL_ID = 'maradigma-elementor-panel';

  // Prevent duplicate global boots.
  if (window.__maradigmaElementorPanelBooted === true) {
    return;
  }

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }
  function qsa(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function getNav() {
    return qs('#elementor-panel-elements-navigation');
  }
  function getWrapper() {
    return qs('#elementor-panel-elements-wrapper');
  }
  function getSearchArea() {
    return qs('#elementor-panel-elements-search-area');
  }

  function escHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function ensureUi(cfg) {
    const nav = getNav();
    const wrapper = getWrapper();
    if (!nav || !wrapper) return false;

    // Button
    let btn = nav.querySelector(`[data-tab="${TAB_NAME}"]`);
    if (!btn) {
      btn = document.createElement('button');
      btn.className = 'elementor-component-tab elementor-panel-navigation-tab';
      btn.type = 'button';
      btn.setAttribute('data-tab', TAB_NAME);
      btn.textContent = (cfg?.i18n?.tabTitle) || 'Maradigma';
      nav.appendChild(btn);
    }

    // Panel
    let panel = qs(`#${PANEL_ID}`, wrapper);
    if (!panel) {
      panel = document.createElement('div');
      panel.id = PANEL_ID;
      panel.className = 'maradigma-elementor-panel';
      panel.style.display = 'none';
      wrapper.appendChild(panel);
    }

    return true;
  }

  function setExclusiveActiveTab(tabName) {
    const nav = getNav();
    if (!nav) return;
    qsa('.elementor-panel-navigation-tab', nav).forEach((b) => {
      b.classList.toggle('elementor-active', b.getAttribute('data-tab') === tabName);
    });
  }

  function hideSearchAreaByJs() {
    const el = getSearchArea();
    if (!el) return;
    if (el.dataset.maradigmaHidden === '1') return;
    el.dataset.maradigmaHidden = '1';
    el.style.display = 'none';
  }

  function showSearchAreaByJs() {
    const el = getSearchArea();
    if (!el) return;
    if (el.dataset.maradigmaHidden === '1') {
      delete el.dataset.maradigmaHidden;
      el.style.display = '';
    }
  }

  function normalizeBoolMeta(v) {
    return v === true || v === '1' || v === 1;
  }

  async function fetchMeta(postType, postId) {
    const path = `/wp/v2/${encodeURIComponent(postType)}/${postId}?context=edit`;
    const data = await wp.apiFetch({ path });
    return (data && data.meta) ? data.meta : {};
  }

  async function saveMeta(postType, postId, partialMeta) {
    const path = `/wp/v2/${encodeURIComponent(postType)}/${postId}`;
    const data = await wp.apiFetch({
      path,
      method: 'POST',
      data: { meta: partialMeta },
    });
    return (data && data.meta) ? data.meta : null;
  }

  function renderPanelOnce(panel, cfg, meta, canEdit) {
    if (panel.dataset.maradigmaRendered === '1') return;

    const i18n = cfg.i18n || {};
    const metaKeys = cfg.metaKeys || {};

    const boatId = String(meta[metaKeys.boatId] || '');
    const useCustom = normalizeBoolMeta(meta[metaKeys.useCustomLayout]);

    panel.innerHTML = `
      <div class="inside">
        <div class="maradigma-boat-cpt-metabox">
          ${!canEdit ? `<p style="margin:0 0 10px;color:#b32d2e;font-size:12px;">
            ${escHtml(i18n.readOnlyNotice || 'Context not available. Panel is in read-only mode.')}
          </p>` : ''}

          <p style="margin:0 0 6px;">
            <label for="maradigma_cpt_boat_id" style="display:block;font-weight:600;">
              ${escHtml(i18n.boatSelectLabel || 'Maradigma boat (searchable)')}
            </label>
          </p>

          <select
            id="maradigma_cpt_boat_id"
            class="widefat maradigma-remote-select"
            data-maradigma-source="boats"
            data-multiple="0"
            ${!canEdit ? 'disabled' : ''}
          >
            ${boatId !== '' ? `<option value="${escHtml(boatId)}" selected>Boat #${escHtml(boatId)}</option>` : ''}
          </select>

          <p style="margin:8px 0 0;color:#666;font-size:12px;line-height:1.3;">
            ${escHtml(i18n.boatSelectHelp || 'Select one of your active/public boats from Maradigma. Start typing to search.')}
          </p>

          <hr style="margin:10px 0;" />

          <p style="margin:0;">
            <label style="display:flex;gap:8px;align-items:flex-start;">
              <input
                type="checkbox"
                id="maradigma_elementor_custom_layout"
                value="1"
                ${useCustom ? 'checked' : ''}
                ${!canEdit ? 'disabled' : ''}
              />
              <span>
                <strong>${escHtml(i18n.useCustomLayout || 'Use custom Elementor layout')}</strong><br/>
                <span style="display:block;margin-top:4px;color:#666;font-size:12px;line-height:1.3;">
                  ${escHtml(i18n.useCustomHelp || "When enabled, sync will never overwrite this boat's Elementor layout. Boat data, images and SEO will still be synced.")}
                </span>
              </span>
            </label>
          </p>
        </div>
      </div>
    `;

    panel.dataset.maradigmaRendered = '1';

    // Initialize Select2 for injected content.
    if (typeof window.MaradigmaRemoteSelect2Init === 'function') {
      window.MaradigmaRemoteSelect2Init(panel);
    }
  }

  async function syncUiValues(panel, cfg, meta) {
    const metaKeys = cfg.metaKeys || {};
    const boatId = String(meta[metaKeys.boatId] || '');
    const useCustom = normalizeBoolMeta(meta[metaKeys.useCustomLayout]);

    const select = panel.querySelector('#maradigma_cpt_boat_id');
    const checkbox = panel.querySelector('#maradigma_elementor_custom_layout');

    if (checkbox) checkbox.checked = useCustom;

    if (select) {
      const current = String(select.value || '');
      if (boatId && current !== boatId) {
        let opt = select.querySelector(`option[value="${CSS.escape(boatId)}"]`);
        if (!opt) {
          opt = document.createElement('option');
          opt.value = boatId;
          opt.textContent = `Boat #${boatId}`;
          select.appendChild(opt);
        }
        opt.selected = true;

        if (window.jQuery) {
          try { window.jQuery(select).trigger('change'); } catch (e) {}
        }
      }
    }
  }

  function showMaradigma(panel) {
    setExclusiveActiveTab(TAB_NAME);
    hideSearchAreaByJs();
    panel.style.display = 'block';
  }

  function hideMaradigma(panel) {
    panel.style.display = 'none';
    showSearchAreaByJs();
  }

  async function boot(cfg) {
    // Mark the global boot as complete.
    window.__maradigmaElementorPanelBooted = true;

    const i18n = cfg.i18n || {};
    const metaKeys = cfg.metaKeys || {};
    const post = cfg.post || {};
    const postId = Number(post.id || 0);
    const postType = String(post.type || '');

    const canEdit = !!(window.wp && wp.apiFetch && postId && postType && metaKeys.boatId && metaKeys.useCustomLayout);

    if (!ensureUi(cfg)) {
      // Retry while the Elementor DOM is not available.
      window.__maradigmaElementorPanelBooted = false;
      return;
    }

    let currentMeta = null;
    let saving = false;

    async function onNavClickCapture(e) {
      ensureUi(cfg);

      const nav = getNav();
      const wrapper = getWrapper();
      if (!nav || !wrapper) return;

      const btn = e.target && e.target.closest ? e.target.closest('.elementor-panel-navigation-tab') : null;
      if (!btn || !nav.contains(btn)) return;

      const tab = btn.getAttribute('data-tab');
      const panel = qs(`#${PANEL_ID}`, wrapper);
      if (!panel) return;

      if (tab !== TAB_NAME) {
        hideMaradigma(panel);
        return;
      }

      e.preventDefault();
      e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();

      showMaradigma(panel);

      if (currentMeta === null) {
        currentMeta = canEdit ? await fetchMeta(postType, postId) : {};
      }

      renderPanelOnce(panel, cfg, currentMeta, canEdit);

      if (!panel.dataset.maradigmaBound) {
        panel.dataset.maradigmaBound = '1';

        const select = panel.querySelector('#maradigma_cpt_boat_id');
        const checkbox = panel.querySelector('#maradigma_elementor_custom_layout');

        if (checkbox) {
          checkbox.addEventListener('change', async () => {
            if (!canEdit || saving) return;
            saving = true;
            try {
              const val = checkbox.checked ? '1' : '';
              const newMeta = await saveMeta(postType, postId, { [metaKeys.useCustomLayout]: val });
              if (newMeta) currentMeta = newMeta;
            } finally {
              saving = false;
            }
          });
        }

        if (select) {
          select.addEventListener('change', async () => {
            if (!canEdit || saving) return;
            saving = true;
            try {
              const val = String(select.value || '').trim();
              const newMeta = await saveMeta(postType, postId, { [metaKeys.boatId]: val });
              if (newMeta) currentMeta = newMeta;
            } finally {
              saving = false;
            }
          });
        }
      } else {
        await syncUiValues(panel, cfg, currentMeta || {});
      }
    }

    function observeElementorDom() {
      const mo = new MutationObserver(() => {
        ensureUi(cfg);

        const wrapper = getWrapper();
        const panel = wrapper ? qs(`#${PANEL_ID}`, wrapper) : null;

        if (panel && panel.style.display === 'block') {
          setExclusiveActiveTab(TAB_NAME);
          hideSearchAreaByJs();
        }
      });

      mo.observe(document.body, { childList: true, subtree: true });
    }

    document.addEventListener('click', onNavClickCapture, true);
    observeElementorDom();
  }

  // Wait for config before evaluating postId and postType.
  let tries = 0;
  const iv = window.setInterval(() => {
    tries++;

    const cfg = window.MaradigmaElementorPanel;
    const nav = getNav();
    const wrapper = getWrapper();

    if (cfg && nav && wrapper) {
      window.clearInterval(iv);
      boot(cfg);
      return;
    }

    if (tries > 160) {
      window.clearInterval(iv);
      console.warn('[Maradigma] Failed to boot: cfg/nav/wrapper not ready');
    }
  }, 100);
})();
