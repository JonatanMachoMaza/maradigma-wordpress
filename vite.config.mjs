import { defineConfig } from 'vite';
import { transformSync } from 'esbuild';
import fs from 'node:fs';
import path from 'node:path';

function flatpickrCssCompatibilityPlugin(rootDir) {
  return {
    name: 'maradigma-flatpickr-css-compatibility',
    closeBundle() {
      const distDir = path.resolve(rootDir, 'assets/dist/css/vendor');

      for (const file of ['flatpickr.css', 'flatpickr.min.css']) {
        const cssPath = path.join(distDir, file);
        if (!fs.existsSync(cssPath)) {
          continue;
        }

        const css = fs.readFileSync(cssPath, 'utf8');
        const sanitized = css.replace(/width:\s*7ch\uFFFD;?/g, '');

        if (sanitized !== css) {
          fs.writeFileSync(cssPath, sanitized, 'utf8');
        }
      }
    },
  };
}

function flatpickrUmdVendorPlugin(rootDir) {
  return {
    name: 'maradigma-flatpickr-umd-vendor',
    closeBundle() {
      const distDir = path.resolve(rootDir, 'assets/dist/js/vendor');
      const flatpickrDist = path.resolve(rootDir, 'node_modules/flatpickr/dist');
      const corePath = path.join(flatpickrDist, 'flatpickr.min.js');
      const l10nDir = path.join(flatpickrDist, 'l10n');

      if (!fs.existsSync(corePath) || !fs.existsSync(l10nDir)) {
        throw new Error('flatpickr package files were not found. Run npm install before building.');
      }

      const localeFiles = fs
        .readdirSync(l10nDir)
        .filter(file => file.endsWith('.js') && file !== 'index.js' && file !== 'default.js')
        .sort((a, b) => a.localeCompare(b));

      const parts = [
        '/* flatpickr v4.6.13 + locales, bundled locally for Maradigma. */',
        fs.readFileSync(corePath, 'utf8'),
        ...localeFiles.map(file => fs.readFileSync(path.join(l10nDir, file), 'utf8')),
        [
          '(function(){',
          '  if (!window.flatpickr || !window.flatpickr.l10ns) return;',
          '  if (window.flatpickr.l10ns.cat && !window.flatpickr.l10ns.ca) {',
          '    window.flatpickr.l10ns.ca = window.flatpickr.l10ns.cat;',
          '  }',
          '})();',
        ].join('\n'),
      ];

      fs.mkdirSync(distDir, { recursive: true });

      const content = parts.join('\n;\n');
      const minified = transformSync(content, {
        charset: 'utf8',
        legalComments: 'none',
        minify: true,
      }).code;

      fs.writeFileSync(path.join(distDir, 'flatpickr.min.js'), minified, 'utf8');
      fs.writeFileSync(path.join(distDir, 'flatpickr.js'), content, 'utf8');
    },
  };
}

function swiperUmdVendorPlugin(rootDir) {
  return {
    name: 'maradigma-swiper-umd-vendor',
    closeBundle() {
      const distDir = path.resolve(rootDir, 'assets/dist/js/vendor');
      const swiperDir = path.resolve(rootDir, 'node_modules/swiper');
      const corePath = path.join(swiperDir, 'swiper-bundle.js');
      const minPath = path.join(swiperDir, 'swiper-bundle.min.js');

      if (!fs.existsSync(corePath) || !fs.existsSync(minPath)) {
        throw new Error('Swiper package files were not found. Run npm install before building.');
      }

      fs.mkdirSync(distDir, { recursive: true });

      const source = fs.readFileSync(corePath, 'utf8').replace(/\n?\/\/# sourceMappingURL=.*$/m, '');
      const minified = fs.readFileSync(minPath, 'utf8').replace(/\n?\/\/# sourceMappingURL=.*$/m, '');

      fs.writeFileSync(path.join(distDir, 'swiper.js'), source, 'utf8');
      fs.writeFileSync(path.join(distDir, 'swiper.min.js'), minified, 'utf8');
    },
  };
}

function noUiSliderUmdVendorPlugin(rootDir) {
  return {
    name: 'maradigma-nouislider-umd-vendor',
    closeBundle() {
      const distDir = path.resolve(rootDir, 'assets/dist/js/vendor');
      const noUiSliderDir = path.resolve(rootDir, 'node_modules/nouislider/dist');
      const corePath = path.join(noUiSliderDir, 'nouislider.js');
      const minPath = path.join(noUiSliderDir, 'nouislider.min.js');

      if (!fs.existsSync(corePath) || !fs.existsSync(minPath)) {
        throw new Error('noUiSlider package files were not found. Run npm install before building.');
      }

      fs.mkdirSync(distDir, { recursive: true });

      const source = fs.readFileSync(corePath, 'utf8').replace(/\n?\/\/# sourceMappingURL=.*$/m, '');
      const minified = fs.readFileSync(minPath, 'utf8').replace(/\n?\/\/# sourceMappingURL=.*$/m, '');

      fs.writeFileSync(path.join(distDir, 'nouislider.js'), source, 'utf8');
      fs.writeFileSync(path.join(distDir, 'nouislider.min.js'), minified, 'utf8');
    },
  };
}

export default defineConfig(({ mode }) => {
  const rootDir = process.cwd();
  const isProd = mode === 'production';

  return {
    publicDir: false,
    esbuild: {
      drop: isProd ? ['console', 'debugger'] : [],
    },
    build: {
      outDir: path.resolve(rootDir, 'assets/dist'),
      emptyOutDir: false,
      minify: isProd ? 'esbuild' : false,
      cssMinify: isProd,
      rollupOptions: {
        input: {
          'css/frontend': path.resolve(rootDir, 'assets/dist/css/frontend.css'),
          'css/admin': path.resolve(rootDir, 'assets/dist/css/admin.css'),
          'css/vendor/flatpickr': path.resolve(rootDir, 'assets/css/vendor/flatpickr.css'),
          'css/vendor/jquery-ui-datepicker': path.resolve(rootDir, 'assets/css/vendor/jquery-ui-datepicker.css'),
          'css/vendor/nouislider': path.resolve(rootDir, 'assets/css/vendor/nouislider.css'),
          'css/vendor/swiper': path.resolve(rootDir, 'assets/css/vendor/swiper.css'),

          'frontend/maradigma-events': path.resolve(rootDir, 'assets/js/frontend/maradigma-events.js'),
          'frontend/maradigma-api-client': path.resolve(rootDir, 'assets/js/frontend/maradigma-api-client.js'),
          'frontend/maradigma-boat-ui': path.resolve(rootDir, 'assets/js/frontend/maradigma-boat-ui.js'),
          'frontend/maradigma': path.resolve(rootDir, 'assets/js/frontend/maradigma.js'),
          'frontend/maradigma-booking-modal': path.resolve(rootDir, 'assets/js/frontend/maradigma-booking-modal.js'),
          'frontend/archive-filters': path.resolve(rootDir, 'assets/js/frontend/archive-filters.js'),

          'admin/admin-page-boats': path.resolve(rootDir, 'assets/js/admin/admin-page-boats.js'),
          'admin/boats-cards': path.resolve(rootDir, 'assets/js/admin/boats-cards.js'),
          'admin/elementor-guards': path.resolve(rootDir, 'assets/js/admin/elementor-guards.js'),
          'admin/elementor-maradigma-panel': path.resolve(rootDir, 'assets/js/admin/elementor-maradigma-panel.js'),
          'admin/elementor-widget': path.resolve(rootDir, 'assets/js/admin/elementor-widget.js'),
          'admin/settings-boats-sync': path.resolve(rootDir, 'assets/js/admin/settings-boats-sync.js'),
          'admin/settings-images-sync': path.resolve(rootDir, 'assets/js/admin/settings-images-sync.js'),
          'admin/settings-seo': path.resolve(rootDir, 'assets/js/admin/settings-seo.js'),
          'admin/settings-template-sync': path.resolve(rootDir, 'assets/js/admin/settings-template-sync.js'),

          'shared/maradigma-boat-calendar': path.resolve(rootDir, 'assets/js/shared/maradigma-boat-calendar.js'),
          'shared/maradigma-boat-gallery': path.resolve(rootDir, 'assets/js/shared/maradigma-boat-gallery.js'),
          'shared/maradigma-icons': path.resolve(rootDir, 'assets/js/shared/maradigma-icons.js'),
          'shared/remote-select2': path.resolve(rootDir, 'assets/js/shared/remote-select2.js'),

          'vendor/swiper': path.resolve(rootDir, 'assets/js/vendor/swiper.js'),
        },
        output: {
          entryFileNames: chunkInfo => {
            return `js/${chunkInfo.name}${isProd ? '.min' : ''}.js`;
          },
          chunkFileNames: chunkInfo => {
            return `js/chunks/${chunkInfo.name}${isProd ? '.min' : ''}.js`;
          },
          assetFileNames: assetInfo => {
            const originalName =
              assetInfo.names && assetInfo.names.length > 0
                ? assetInfo.names[0]
                : assetInfo.name || 'asset';

            if (originalName.endsWith('.css')) {
              const cleanName = originalName.replace(/\.css$/i, '');
              return `${cleanName}${isProd ? '.min' : ''}.css`;
            }

            const ext = path.extname(originalName);
            const name = path.basename(originalName, ext);

            return `assets/${name}${isProd ? '.min' : ''}${ext}`;
          },
        },
      },
    },
    plugins: [
      flatpickrCssCompatibilityPlugin(rootDir),
      flatpickrUmdVendorPlugin(rootDir),
      swiperUmdVendorPlugin(rootDir),
      noUiSliderUmdVendorPlugin(rootDir),
    ],
  };
});
