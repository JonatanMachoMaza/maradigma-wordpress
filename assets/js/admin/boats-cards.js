/* global jQuery, MaradigmaCardsAdmin, MaradigmaConfig */
(function ($) {
  'use strict';

  const SELECTOR_TEXTAREA = '#maradigma-card-template';
  const SELECTOR_TOKEN_SELECT = '#maradigma-placeholder-select';
  const SELECTOR_INSERT_BTN = '#maradigma-insert-placeholder-btn';
  const SELECTOR_PREVIEW_IFRAME = '#maradigma-card-preview-iframe';
  const SELECTOR_REFRESH_PREVIEW = '#maradigma-refresh-preview-btn';

  function getTextareaEl() {
    return document.querySelector(SELECTOR_TEXTAREA);
  }

  function getIframeEl() {
    return document.querySelector(SELECTOR_PREVIEW_IFRAME);
  }

  function getFrontendCssUrls() {
    const cfg = window.MaradigmaCardsAdmin || {};

    // Preferimos array
    if (Array.isArray(cfg.frontendCssUrls)) {
      return cfg.frontendCssUrls.map(u => String(u || '').trim()).filter(Boolean);
    }

    // Legacy string
    if (cfg.frontendCssUrl) {
      const u = String(cfg.frontendCssUrl || '').trim();
      return u ? [u] : [];
    }

    return [];
  }

  function escapeHtmlAttr(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // SVG sprite for iframe preview

  function buildSvgSpriteMarkup() {
    // MaradigmaConfig.svgs must also be localized in the admin.
    const cfg = window.MaradigmaConfig || {};
    const svgs = cfg.svgs || null;

    if (!svgs || typeof svgs !== 'object') {
      return '';
    }

    const symbols = Object.keys(svgs)
      .map((k) => String(svgs[k] || '').trim())
      .filter(Boolean)
      .join('');

    if (!symbols) {
      return '';
    }

    return (
      '<div id="maradigma-svg-sprite" aria-hidden="true" style="position:absolute;width:0;height:0;overflow:hidden;">' +
        '<svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden;">' +
          symbols +
        '</svg>' +
      '</div>'
    );
  }

  function getIconBaseCss() {
    // Para asegurar que el <svg> renderiza aunque no venga con width/height.
    // Do not impose fixed dimensions.
    return `
      svg { display:inline-block; vertical-align:middle; }
      svg:not([width]) { width: 1em; }
      svg:not([height]) { height: 1em; }
      svg { fill: currentColor; }
    `.trim();
  }

  // Code editor (CodeMirror)

  function initCodeEditorOnce() {
    const textareaEl = getTextareaEl();
    if (!textareaEl) return null;

    if (textareaEl.dataset.maradigmaCodemirrorInit === '1') {
      return textareaEl._maradigmaCodeMirror || null;
    }

    textareaEl.dataset.maradigmaCodemirrorInit = '1';

    const cfg = window.MaradigmaCardsAdmin || {};
    const settings = cfg.codeEditorSettings || null;

    if (!settings || !window.wp || !window.wp.codeEditor) {
      textareaEl._maradigmaCodeMirror = null;
      return null;
    }

    const editor = window.wp.codeEditor.initialize(textareaEl, settings);
    const cm = editor && editor.codemirror ? editor.codemirror : null;

    textareaEl._maradigmaCodeMirror = cm || null;
    return cm || null;
  }

  function getEditorValue() {
    const textareaEl = getTextareaEl();
    if (!textareaEl) return '';
    const cm = textareaEl._maradigmaCodeMirror;
    return cm ? cm.getValue() : (textareaEl.value || '');
  }

  function insertAtCursor(token) {
    const textareaEl = getTextareaEl();
    if (!textareaEl || !token) return;

    const cm = textareaEl._maradigmaCodeMirror;
    if (cm) {
      cm.focus();
      cm.replaceSelection(token);
      try { cm.execCommand('indentAuto'); } catch (e) {}
      cm.setCursor(cm.getCursor());
      return;
    }

    textareaEl.focus();
    const start = textareaEl.selectionStart || 0;
    const end = textareaEl.selectionEnd || 0;
    const val = textareaEl.value || '';
    textareaEl.value = val.slice(0, start) + token + val.slice(end);
    const pos = start + token.length;
    textareaEl.selectionStart = textareaEl.selectionEnd = pos;
  }

  // Select2 tokens

  function initSelect2Tokens() {
    const $select = $(SELECTOR_TOKEN_SELECT);
    if ($select.length === 0) return;

    const cfg = window.MaradigmaCardsAdmin || {};
    const tokens = Array.isArray(cfg.tokens)
      ? cfg.tokens.filter((t) => t && String(t.id || '').trim() !== '')
      : [];

    if (tokens.length > 0) {
      $select.empty().append(new Option('Select a variable...', ''));
      tokens.forEach((t) => {
        const id = String(t.id).trim();
        const labelText = String(t.label || '').trim();
        const label = labelText ? `${labelText} - ${id}` : id;
        $select.append(new Option(label, id));
      });
    }

    if (typeof $select.select2 === 'function') {
      $select.select2({
        width: 'resolve',
        placeholder: 'Select a variable...',
        allowClear: true,
        minimumResultsForSearch: 0
      });
    }
  }

  // Preview render pipeline

  function stripScripts(html) {
    // elimina <script>...</script>
    let out = String(html || '').replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '');

    // elimina atributos inline tipo onclick="..."
    out = out.replace(/\son\w+\s*=\s*(['"]).*?\1/gi, '');

    return out;
  }

  function replaceTokens(rawHtml) {
    const cfg = window.MaradigmaCardsAdmin || {};
    const sample = cfg.previewSample || {};

    let out = String(rawHtml || '');

    Object.keys(sample).forEach((k) => {
      out = out.split(k).join(String(sample[k]));
    });

    // limpia tokens no resueltos
    out = out.replace(/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/g, '');

    return out;
  }

  function writeIframe(innerHtml) {
    const iframe = getIframeEl();
    if (!iframe) return;

    const doc = iframe.contentDocument || (iframe.contentWindow ? iframe.contentWindow.document : null);
    if (!doc) return;

    const cssUrls = getFrontendCssUrls();

    if (!cssUrls.length) {
      console.warn('[Maradigma] frontendCssUrls is empty. CSS will not load in iframe. Check wp_localize_script.');
    }

    const cssLinks = cssUrls
      .map((u) => `<link rel="stylesheet" href="${escapeHtmlAttr(u)}">`)
      .join('\n');

    const bodyHtml = (innerHtml && innerHtml.trim())
      ? innerHtml
      : '<em style="color:#666;font-family:Arial,sans-serif;">Preview will appear here.</em>';

    const spriteMarkup = buildSvgSpriteMarkup();
    if (!spriteMarkup) {
      console.warn('[Maradigma] MaradigmaConfig.svgs is empty or missing. SVG icons may not render in iframe preview.');
    }

    const fullDoc =
`<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  ${cssLinks}
  <style>
    body { margin:0; padding:16px; background:#fff; }
    .maradigma-preview-wrap { max-width: 1200px; margin: 0 auto; }
    a { pointer-events: none; }
    ${getIconBaseCss()}
  </style>
</head>
<body>
  ${spriteMarkup}
  <div class="maradigma-preview-wrap">
    ${bodyHtml}
  </div>
</body>
</html>`;

    doc.open();
    doc.write(fullDoc);
    doc.close();

    // Bloqueo de clicks desde el padre (sin scripts en iframe)
    const block = function (e) {
      const t = e.target;
      const a = t && t.closest ? t.closest('a') : null;
      const form = t && t.closest ? t.closest('form') : null;
      const btn = t && t.closest ? t.closest('button') : null;

      if (a || form || (btn && (btn.type === 'submit' || btn.getAttribute('type') === 'submit'))) {
        e.preventDefault();
        e.stopPropagation();
      }
    };

    try {
      doc.removeEventListener('click', block, true);
      doc.addEventListener('click', block, true);
    } catch (e) {}
  }

  function renderPreview() {
    const raw = getEditorValue();
    const safe = stripScripts(raw);
    const rendered = replaceTokens(safe);
    writeIframe(rendered);
  }

  // UI bindings

  function bindUI() {
    $(document).on('click', SELECTOR_INSERT_BTN, function () {
      const token = $(SELECTOR_TOKEN_SELECT).val();
      if (!token) return;
      insertAtCursor(String(token));
      renderPreview();
    });

    $(document).on('click', SELECTOR_REFRESH_PREVIEW, function () {
      renderPreview();
    });

    let t = null;
    const textareaEl = getTextareaEl();
    if (!textareaEl) return;

    const cm = textareaEl._maradigmaCodeMirror;
    if (cm) {
      cm.on('change', function () {
        clearTimeout(t);
        t = setTimeout(renderPreview, 250);
      });
    } else {
      textareaEl.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(renderPreview, 250);
      });
    }
  }

  $(function () {

    initCodeEditorOnce();
    initSelect2Tokens();
    bindUI();
    renderPreview();
  });

})(jQuery);
