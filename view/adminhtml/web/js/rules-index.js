(function (doc) {
  'use strict';

  // Saved rules: a dropdown change applies at once; the Apply button stays for keyboard and no-JS use.
  var filters = doc.querySelector('[data-ffb-rules-filters]');
  if (filters) {
    Array.prototype.forEach.call(filters.querySelectorAll('select'), function (select) {
      select.addEventListener('change', function () { filters.submit(); });
    });
  }

  // Built-in rules: instant search and category chips, all client side.
  var box = doc.querySelector('[data-ffb-builtin]');
  if (!box) {
    return;
  }
  var search = box.querySelector('[data-ffb-builtin-search]');
  var chips = box.querySelectorAll('[data-ffb-builtin-chip]');
  var groups = box.querySelectorAll('[data-ffb-builtin-group]');
  var empty = box.querySelector('[data-ffb-builtin-empty]');
  var group = '';

  function apply() {
    var q = search.value.trim().toLowerCase();
    var shown = 0;
    Array.prototype.forEach.call(groups, function (g) {
      var rows = 0;
      Array.prototype.forEach.call(g.querySelectorAll('[data-ffb-builtin-row]'), function (row) {
        row.hidden = q !== '' && row.getAttribute('data-text').indexOf(q) === -1;
        rows += row.hidden ? 0 : 1;
      });
      g.hidden = rows === 0 || (group !== '' && g.getAttribute('data-ffb-builtin-group') !== group);
      shown += g.hidden ? 0 : rows;
    });
    empty.hidden = shown > 0;
  }

  Array.prototype.forEach.call(chips, function (chip) {
    chip.addEventListener('click', function () {
      group = chip.getAttribute('data-ffb-builtin-chip');
      Array.prototype.forEach.call(chips, function (other) { other.setAttribute('aria-pressed', String(other === chip)); });
      apply();
    });
  });
  search.addEventListener('input', apply);
  box.querySelector('[data-ffb-builtin-tools]').hidden = false;
}(document));
