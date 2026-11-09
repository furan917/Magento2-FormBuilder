(function (doc) {
  'use strict';

  // Slow requests (e.g. mail sending) invite a second click; the first submit already counts.
  doc.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.hasAttribute || !form.hasAttribute('data-ffb-submit-once')) {
      return;
    }
    if (form.getAttribute('data-ffb-submitted') === '1') {
      event.preventDefault();
      return;
    }
    form.setAttribute('data-ffb-submitted', '1');
    Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], input[type="submit"]'), function (button) {
      button.disabled = true;
    });
  });
}(document));
