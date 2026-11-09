(function (doc) {
  'use strict';

  function each(list, fn) {
    Array.prototype.forEach.call(list, fn);
  }

  function initScope() {
    var radios = doc.querySelectorAll('input[data-ffb-scope-type]');
    if (!radios.length || radios[0].disabled) {
      return;
    }
    function apply() {
      var chosen = '';
      each(radios, function (radio) {
        chosen = radio.checked ? radio.value : chosen;
      });
      each(doc.querySelectorAll('[data-ffb-scope-panel]'), function (panel) {
        panel.hidden = panel.getAttribute('data-ffb-scope-panel') !== chosen;
      });
    }
    each(radios, function (radio) {
      radio.addEventListener('change', apply);
    });
    apply();
  }

  function initFieldMode() {
    var mode = doc.querySelector('[data-ffb-field-mode]');
    var names = doc.querySelector('[data-ffb-field-names]');
    if (!mode || !names || mode.disabled) {
      return;
    }
    function apply() {
      names.disabled = mode.value === 'all';
    }
    mode.addEventListener('change', apply);
    apply();
  }

  function initSecret() {
    var section = doc.querySelector('[data-ffb-secret-section]');
    if (!section) {
      return;
    }
    var button = section.querySelector('[data-ffb-copy-secret]');
    var status = section.querySelector('[data-ffb-copy-status]');
    var input = button && doc.getElementById(button.getAttribute('data-ffb-copy-secret'));
    if (button && input && doc.defaultView.navigator.clipboard) {
      button.hidden = false;
      button.addEventListener('click', function () {
        doc.defaultView.navigator.clipboard.writeText(input.value).then(function () {
          status.textContent = button.getAttribute('data-ffb-copied');
        });
      });
    }
    section.focus();
  }

  initScope();
  initFieldMode();
  initSecret();
}(document));
