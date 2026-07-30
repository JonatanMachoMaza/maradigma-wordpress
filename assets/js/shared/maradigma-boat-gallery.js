(function ($) {

  // ------------------------------------------------------------
  // Helpers: icons (sprite)
  // ------------------------------------------------------------
  function iconsReady() {
    return (window.MaradigmaIcons && typeof window.MaradigmaIcons.renderIcon === 'function');
  }

  function injectIconsSpriteOnce() {
    if (window.MaradigmaIcons && typeof window.MaradigmaIcons.injectSvgSpriteOnce === 'function') {
      window.MaradigmaIcons.injectSvgSpriteOnce();
    }
  }

  function iconHtml(key, opts) {
    opts = opts || {};
    if (!iconsReady()) {
      // Fallback chars
      if (key === 'xmark') return '×';
      if (key === 'chevron-left') return '‹';
      if (key === 'chevron-right') return '›';
      return '';
    }
    return window.MaradigmaIcons.renderIcon(key, {
      className: opts.className || 'md-lightbox__icon',
      title: opts.title || '',
      width: opts.width || 18,
      height: opts.height || 18
    });
  }

  // ------------------------------------------------------------
  // Maradigma Lightbox (no dependency)
  // ------------------------------------------------------------
  function ensureLightboxDom() {
    if (document.getElementById('mdLightbox')) return;

    // Ensure sprite is present (best-effort)
    injectIconsSpriteOnce();

    var html =
      '<div id="mdLightbox" class="md-lightbox" aria-hidden="true">' +
        '<div class="md-lightbox__backdrop" data-mdlb-close></div>' +
        '<div class="md-lightbox__panel" role="dialog" aria-modal="true">' +

          '<button class="md-lightbox__btn md-lightbox__btn--close" type="button" aria-label="Close" data-mdlb-close>' +
            iconHtml('xmark', { className: 'md-lightbox__icon md-lightbox__icon--close', width: 18, height: 18 }) +
          '</button>' +

          '<button class="md-lightbox__btn md-lightbox__btn--prev" type="button" aria-label="Previous" data-mdlb-prev>' +
            iconHtml('chevron-left', { className: 'md-lightbox__icon md-lightbox__icon--prev', width: 22, height: 22 }) +
          '</button>' +

          '<button class="md-lightbox__btn md-lightbox__btn--next" type="button" aria-label="Next" data-mdlb-next>' +
            iconHtml('chevron-right', { className: 'md-lightbox__icon md-lightbox__icon--next', width: 22, height: 22 }) +
          '</button>' +

          '<div class="md-lightbox__counter" data-mdlb-counter></div>' +
          '<img class="md-lightbox__img" data-mdlb-img alt="" />' +
        '</div>' +
      '</div>';

    var wrap = document.createElement('div');
    wrap.innerHTML = html;
    document.body.appendChild(wrap.firstChild);

    var root = document.getElementById('mdLightbox');

    root.addEventListener('click', function (ev) {
      if (ev.target && ev.target.closest && ev.target.closest('[data-mdlb-close]')) {
        closeLightbox();
      }
    });

    document.addEventListener('keydown', function (ev) {
      if (!isOpen()) return;
      if (ev.key === 'Escape') closeLightbox();
      if (ev.key === 'ArrowLeft') nav(-1);
      if (ev.key === 'ArrowRight') nav(1);
    });
  }

  var state = { items: [], index: 0 };

  function isOpen() {
    var root = document.getElementById('mdLightbox');
    return !!root && root.getAttribute('aria-hidden') === 'false';
  }

  function openLightbox(items, startIndex) {
    ensureLightboxDom();

    state.items = items || [];
    state.index = Math.max(0, Math.min(Number(startIndex || 0), state.items.length - 1));

    var root = document.getElementById('mdLightbox');
    root.setAttribute('aria-hidden', 'false');
    root.classList.add('md-lightbox--open');

    render();
  }

  function closeLightbox() {
    var root = document.getElementById('mdLightbox');
    if (!root) return;
    root.setAttribute('aria-hidden', 'true');
    root.classList.remove('md-lightbox--open');
  }

  function nav(dir) {
    if (!state.items.length) return;
    state.index = (state.index + dir + state.items.length) % state.items.length;
    render();
  }

  function render() {
    var root = document.getElementById('mdLightbox');
    if (!root) return;

    var img = root.querySelector('[data-mdlb-img]');
    var counter = root.querySelector('[data-mdlb-counter]');
    var prev = root.querySelector('[data-mdlb-prev]');
    var next = root.querySelector('[data-mdlb-next]');

    var item = state.items[state.index];
    if (!item) return;

    img.src = item.url;
    img.alt = item.alt || '';

    if (counter) counter.textContent = (state.index + 1) + ' / ' + state.items.length;

    var single = state.items.length <= 1;
    if (prev) prev.style.display = single ? 'none' : '';
    if (next) next.style.display = single ? 'none' : '';
  }

  // Delegated buttons
  document.addEventListener('click', function (ev) {
    if (!isOpen()) return;

    var t = ev.target;
    if (!t || !t.closest) return;

    if (t.closest('[data-mdlb-prev]')) { ev.preventDefault(); nav(-1); }
    if (t.closest('[data-mdlb-next]')) { ev.preventDefault(); nav(1); }
  });

  // ------------------------------------------------------------
  // Swiper init + click binding
  // ------------------------------------------------------------
  function initIn(contextEl) {
    var ctx = contextEl || document;

    var roots = ctx.querySelectorAll('.maradigma-boat-gallery');
    if (!roots || !roots.length) return;

    roots.forEach(function (root) {

      // 1) Lightbox click bind (grid + slider)
      if (!root.__mdLbBound) {
        root.__mdLbBound = true;

        root.addEventListener('click', function (ev) {
          var a = ev.target && ev.target.closest ? ev.target.closest('a[data-elementor-open-lightbox="yes"]') : null;
          if (!a) return;

          var href = (a.getAttribute('href') || '').trim();
          if (!href) return;

          ev.preventDefault();
          ev.stopPropagation();

          var slideshow = (a.getAttribute('data-elementor-lightbox-slideshow') || '').trim();

          // NOTE: in a slider with loop, duplicates exist; we will ignore duplicates
          var selector = slideshow
            ? 'a[data-elementor-open-lightbox="yes"][data-elementor-lightbox-slideshow="' + CSS.escape(slideshow) + '"]'
            : 'a[data-elementor-open-lightbox="yes"]';

          var anchors = root.querySelectorAll(selector);
          var items = [];
          var seen = new Set();

          anchors.forEach(function (x) {
            var slide = x.closest('.swiper-slide');
            if (slide && slide.classList.contains('swiper-slide-duplicate')) return;

            var u = (x.getAttribute('href') || '').trim();
            if (!u || seen.has(u)) return;
            seen.add(u);

            var img = x.querySelector('img');
            items.push({
              url: u,
              alt: img ? (img.getAttribute('alt') || '') : ''
            });
          });

          var startIndex = 0;
          for (var i = 0; i < items.length; i++) {
            if (items[i].url === href) { startIndex = i; break; }
          }

          openLightbox(items, startIndex);
        }, true);
      }

      // 2) Swiper init only for slider
      if (!root.classList.contains('maradigma-boat-gallery--slider')) return;
      if (root.__maradigmaSwiper) return;

      var raw = root.getAttribute('data-maradigma-gallery') || '{}';
      var cfg = {};
      try { cfg = JSON.parse(raw); } catch (e) { cfg = {}; }

      var elSwiper = root.querySelector('.swiper');
      if (!elSwiper || typeof window.Swiper === 'undefined') return;

      var options = {
        speed: Number(cfg.speed || 400),
        loop: !!cfg.loop,
        spaceBetween: Number(cfg.spaceBetween || 10),
        slidesPerView: Number(cfg.perViewDesktop || 1),
        watchOverflow: true,

        observer: true,
        observeParents: true,
        updateOnWindowResize: true,

        breakpoints: {
          0:    { slidesPerView: Number(cfg.perViewMobile || 1) },
          768:  { slidesPerView: Number(cfg.perViewTablet || 1) },
          1024: { slidesPerView: Number(cfg.perViewDesktop || 1) }
        },

        preventClicks: false,
        preventClicksPropagation: false,
        touchStartPreventDefault: false
      };

      if (cfg.autoplay) {
        options.autoplay = { delay: Number(cfg.delay || 3500), disableOnInteraction: false };
      }
      if (cfg.pagination) {
        var pag = root.querySelector('.swiper-pagination');
        if (pag) options.pagination = { el: pag, clickable: true };
      }
      if (cfg.navigation) {
        var prev = root.querySelector('.swiper-button-prev');
        var next = root.querySelector('.swiper-button-next');
        if (prev && next) options.navigation = { prevEl: prev, nextEl: next };
      }

      root.__maradigmaSwiper = new Swiper(elSwiper, options);
    });
  }

  function bindElementorHook() {
    if (!window.elementorFrontend || !elementorFrontend.hooks || !elementorFrontend.hooks.addAction) return;

    elementorFrontend.hooks.addAction(
      'frontend/element_ready/maradigma_boat_gallery.default',
      function ($scope) {
        initIn($scope && $scope[0] ? $scope[0] : document);
      }
    );
  }

  function scheduleInit(contextEl) {
    window.clearTimeout(scheduleInit.timer);
    scheduleInit.timer = window.setTimeout(function () {
      initIn(contextEl || document);
    }, 50);
  }
  scheduleInit.timer = null;

  function nodeHasGallery(node) {
    if (!node || node.nodeType !== 1) return false;
    if (node.matches && node.matches('.maradigma-boat-gallery')) return true;
    return Boolean(node.querySelector && node.querySelector('.maradigma-boat-gallery'));
  }

  function observeDynamicGalleries() {
    if (!('MutationObserver' in window)) return;

    var target = document.body || document.documentElement;
    if (!target) return;

    var observer = new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var nodes = mutations[i].addedNodes || [];
        for (var j = 0; j < nodes.length; j++) {
          if (nodeHasGallery(nodes[j])) {
            scheduleInit(document);
            return;
          }
        }
      }
    });

    observer.observe(target, { childList: true, subtree: true });
  }

  $(window).on('elementor/frontend/init', bindElementorHook);
  bindElementorHook();
  $(function () {
    initIn(document);
    observeDynamicGalleries();
  });
  $(window).on('load', function () {
    scheduleInit(document);
  });

})(jQuery);
