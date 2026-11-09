(function (root) {
  'use strict';

  // Inline handlers are blocked by CSP, so confirmation prompts are wired here.
  root.document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form && form.getAttribute ? form.getAttribute('data-ffb-confirm') : null;
    if (message && !root.confirm(message)) {
      event.preventDefault();
    }
  });
}(window));
