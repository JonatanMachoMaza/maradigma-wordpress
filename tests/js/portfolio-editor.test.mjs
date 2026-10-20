import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';

const UUID = '12345678-1234-4321-8123-000000000012';
const source = fs.readFileSync(new URL('../../assets/js/admin/elementor-widget.js', import.meta.url), 'utf8');
const initialize = source.slice(source.indexOf('  function initAjaxSelect2ForElement('), source.indexOf('  function hydrateSelectedLabels('));

function editor(savedValue) {
  let setting = savedValue;
  let config;
  let selected = null;
  const options = [];
  const handlers = {};
  const writes = [];
  const hidden = {
    length: 1,
    val(value) {
      if (arguments.length) {
        setting = value;
        writes.push(value);
        return hidden;
      }
      return setting;
    },
    trigger() { return hidden; },
    first() { return hidden; },
  };
  const stack = { find() { return hidden; } };
  const control = { closest() { return stack; } };
  const panel = { length: 1 };
  const select = {
    length: 1,
    hasClass() { return false; },
    data(key) { return { 'maradigma-source': 'portfolios', multiple: '0', 'setting-key': 'portfolio' }[key]; },
    closest(selector) { return selector === '#elementor-panel' ? panel : control; },
    select2(value) { config = value; return select; },
    append(option) { options.push(option); selected = option.value; return select; },
    trigger() { return select; },
    on(event, callback) { handlers[event] = callback; return select; },
    val() { return selected; },
  };
  const context = vm.createContext({
    cfg: {}, ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'editor-nonce', search: {}, i18n: {}, console,
    Option: function (text, value) { this.text = text; this.value = value; },
    hydrateSelectedLabels() {},
  });
  vm.runInContext(initialize, context);
  context.initAjaxSelect2ForElement(select);
  return {
    options, writes,
    get config() { return config; },
    get setting() { return setting; },
    choose(uuid) { selected = uuid; handlers.change(); },
  };
}

test('Elementor loads UUID portfolios through its authenticated editor action and retains the saved UUID', () => {
  const control = editor(UUID);
  assert.equal(control.config.ajax.data({ term: 'Fleet' }).action, 'maradigma_elementor_search_portfolios');
  assert.equal(control.config.ajax.data({}).nonce, 'editor-nonce');
  assert.deepEqual(control.options.map(option => option.value), [UUID]);
  assert.equal(control.setting, UUID);
  assert.deepEqual(control.writes, []);
});

test('opening Elementor does not offer an old numeric portfolio or silently clear its restriction', () => {
  const control = editor('12');
  assert.deepEqual(control.options, []);
  assert.deepEqual(control.writes, []);
  assert.equal(control.setting, '12');
  control.choose(UUID);
  assert.equal(control.setting, UUID);
});
