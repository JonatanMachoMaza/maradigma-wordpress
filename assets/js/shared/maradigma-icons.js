// assets/js/shared/maradigma-icons.js
(function (w, d) {
  'use strict';

  if (typeof w.MaradigmaConfig === 'undefined') {
    return;
  }

  // ─────────────────────────────────────────────
  // SVG sprite helpers
  // ─────────────────────────────────────────────

  function injectSvgSpriteOnce() {
    if (!w.MaradigmaConfig.svgs || typeof w.MaradigmaConfig.svgs !== 'object') {
      return;
    }

    if (d.getElementById('maradigma-svg-sprite')) {
      return;
    }

    var sprite = d.createElement('div');
    sprite.id = 'maradigma-svg-sprite';
    sprite.style.position = 'absolute';
    sprite.style.width = '0';
    sprite.style.height = '0';
    sprite.style.overflow = 'hidden';
    sprite.setAttribute('aria-hidden', 'true');

    var symbols = Object.keys(w.MaradigmaConfig.svgs).map(function (k) {
      return w.MaradigmaConfig.svgs[k];
    }).join('');

    // + compat xlink
    sprite.innerHTML =
      '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="position:absolute;width:0;height:0;overflow:hidden;">' +
        symbols +
      '</svg>';

    // Insertar lo antes posible
    if (d.body) {
      d.body.insertBefore(sprite, d.body.firstChild);
    } else {
      d.addEventListener('DOMContentLoaded', function () {
        d.body.insertBefore(sprite, d.body.firstChild);
      });
    }
  }

  /**
   * Return SVG markup that references a symbol id inside the sprite.
   * @param {string} iconKey Example: 'map-marker'
   * @param {Object} options
   * @param {string} [options.className]
   * @param {string} [options.title]
   * @param {string|number} [options.width] default 14
   * @param {string|number} [options.height] default 14
   * @returns {string}
   */
  function renderIcon(iconKey, options) {
    options = options || {};
    var symbolId = 'svg-' + iconKey;

    var title = options.title ? '<title>' + String(options.title) + '</title>' : '';
    var className = options.className ? String(options.className) : '';
    var wAttr = options.width != null ? String(options.width) : '14';
    var hAttr = options.height != null ? String(options.height) : '14';

    return (
      '<svg class="' + className + '" width="' + wAttr + '" height="' + hAttr + '" aria-hidden="' + (options.title ? 'false' : 'true') + '" role="img">' +
        title +
        '<use href="#' + symbolId + '" xlink:href="#' + symbolId + '"></use>' +
      '</svg>'
    );
  }

  // Exponer en global para frontend/admin
  w.MaradigmaIcons = w.MaradigmaIcons || {};
  w.MaradigmaIcons.injectSvgSpriteOnce = injectSvgSpriteOnce;
  w.MaradigmaIcons.renderIcon = renderIcon;

  // Inyectar inmediatamente (y por si acaso en DOMContentLoaded)
  injectSvgSpriteOnce();
  d.addEventListener('DOMContentLoaded', injectSvgSpriteOnce);

})(window, document);
