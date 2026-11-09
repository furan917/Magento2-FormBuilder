(function (root) {
  'use strict';

  var ns = root.FuranFormBuilder = root.FuranFormBuilder || {};
  if (ns.submit) {
    return;
  }

  var doc = root.document;
  var KEY_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  var TOKEN_MARGIN_MS = 1000;
  var LONG_WAIT_MS = 60000;

  var randomKey = function () {
    var bytes = new Uint8Array(16);
    root.crypto.getRandomValues(bytes);
    return Array.prototype.map.call(bytes, function (b) { return KEY_CHARS.charAt(b % KEY_CHARS.length); }).join('');
  };

  var readCookie = function (name) {
    var match = doc.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    if (!match) {
      return '';
    }
    try {
      return decodeURIComponent(match[1]);
    } catch (e) {
      return '';
    }
  };

  var resolveFormKey = function () {
    try {
      if (root.hyva && typeof root.hyva.getFormKey === 'function') {
        var fromHyva = root.hyva.getFormKey();
        if (fromHyva) {
          return String(fromHyva);
        }
      }
    } catch (e) { /* fall through to cookie */ }
    var cookie = readCookie('form_key');
    if (cookie) {
      return cookie;
    }
    var key = randomKey();
    doc.cookie = 'form_key=' + key + '; path=/; max-age=86400; samesite=lax' +
      (root.location.protocol === 'https:' ? '; secure' : '');
    return key;
  };

  var nowMs = function () {
    return root.performance && typeof root.performance.now === 'function' ? root.performance.now() : Date.now();
  };

  var sleep = function (ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
  };

  // Inputs: form, tokenInput, keyInput, submitBtn, messages, submitUrl,
  // getTokenUrl(), productId, getEditToken() -> '' or the token in force, recaptcha {getToken() -> Promise, reset()} or null,
  // prepare() before reading the form, setStatus(text), onToken(data) -> Promise|null, onSettled() after every token request,
  // onSuccess(data), onFieldErrors(errors) -> true when a field was flagged.
  var create = function (o) {
    var token = '';
    var tokenRequest = null;
    var acceptAt = 0;
    var submitting = false;
    var holding = false;

    var setBusy = function (busy) {
      submitting = busy;
      if (!o.submitBtn) { return; }
      // aria-disabled keeps focus on the button; the form's submit listener always prevents the native post.
      if (busy) {
        o.submitBtn.setAttribute('aria-busy', 'true');
        o.submitBtn.setAttribute('aria-disabled', 'true');
      } else {
        o.submitBtn.removeAttribute('aria-busy');
        o.submitBtn.removeAttribute('aria-disabled');
      }
    };

    var showSending = function () {
      o.setStatus(String(o.messages.sending || ''));
    };

    var resetRecaptcha = function () {
      if (o.recaptcha) { o.recaptcha.reset(); }
    };

    var remainingWait = function () {
      return token && acceptAt ? Math.max(0, acceptAt - nowMs()) : 0;
    };

    var waitUntilAccepted = function () {
      var ms = remainingWait();
      if (ms <= 0) {
        return Promise.resolve();
      }
      if (!holding) { showSending(); }
      return sleep(ms);
    };

    var ensureToken = function () {
      if (token) {
        return Promise.resolve();
      }
      if (!tokenRequest) {
        var url = o.getTokenUrl();
        tokenRequest = (url
          ? root.fetch(url, { method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
          : Promise.reject(new Error('Token URL refused')))
          .then(function (res) { return res.json(); })
          .then(function (data) {
            token = data && typeof data.token === 'string' ? data.token : '';
            if (o.tokenInput) { o.tokenInput.value = token; }
            // Relative seconds from the server, plus 1 s for whole-second token stamps and clock skew between nodes.
            var notBefore = data ? Number(data.notBefore) : 0;
            acceptAt = token && notBefore > 0 && isFinite(notBefore) ? nowMs() + notBefore * 1000 + TOKEN_MARGIN_MS : 0;
            return o.onToken(data);
          })
          .catch(function () { /* retried before submit */ })
          .then(function () {
            tokenRequest = null;
            o.onSettled();
          });
      }
      return tokenRequest;
    };

    var applySecrets = function () {
      if (o.keyInput) { o.keyInput.value = resolveFormKey(); }
      if (o.tokenInput) { o.tokenInput.value = token; }
    };

    var dropToken = function () {
      token = '';
      if (o.tokenInput) { o.tokenInput.value = ''; }
    };

    var onResponse = function (res, data) {
      if (res.ok && data && data.success) {
        token = '';
        o.onSuccess(data);
        return;
      }
      if (res.status === 422 && data && o.onFieldErrors(data.errors)) {
        o.setStatus(String(o.messages.fixErrors || ''));
      } else {
        o.setStatus(String((data && data.message) || o.messages.generic || ''));
        if (res.status === 400 || res.status === 403) {
          dropToken();
        }
      }
      resetRecaptcha();
      setBusy(false);
    };

    var send = function (rcToken, retried) {
      showSending();
      o.prepare();
      applySecrets();
      var body = new root.FormData(o.form);
      Array.prototype.forEach.call(o.form.querySelectorAll('input[type="range"][data-ffb-unset]'), function (r) { body.delete(r.name); });
      if (rcToken) { body.set('g-recaptcha-response', rcToken); }
      if (o.productId) { body.set('ffb_product_id', String(o.productId)); }
      var editToken = o.getEditToken();
      if (editToken) { body.set('ffb_edit', editToken); }
      var request = o.submitUrl
        ? root.fetch(o.submitUrl, {
          method: 'POST',
          body: body,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
        })
        : Promise.reject(new Error('Submit URL refused'));
      return request.then(function (res) {
        if (res.status === 425 && !retried) {
          // Too early: the server refused before touching the token or reCAPTCHA, so both stay valid.
          showSending();
          return sleep(TOKEN_MARGIN_MS).then(function () { return send(rcToken, true); });
        }
        return res.json().then(function (data) { return data; }, function () { return null; })
          .then(function (data) { onResponse(res, data); });
      }, function () {
        o.setStatus(String(o.messages.network || ''));
        resetRecaptcha();
        setBusy(false);
      });
    };

    var fetchRecaptchaToken = function (onToken) {
      return o.recaptcha.getToken().then(function (rcToken) {
        if (!rcToken) {
          o.setStatus(String(o.messages.recaptchaRequired || ''));
          return null;
        }
        return onToken(rcToken);
      }, function () {
        o.setStatus(String(o.messages.generic || ''));
        resetRecaptcha();
      });
    };

    // The button stays enabled during a long wait (nothing is pending with Google yet); `holding` blocks a second run.
    var submitAfterLongWait = function () {
      holding = true;
      if (o.submitBtn) { o.submitBtn.setAttribute('aria-disabled', 'true'); }
      o.setStatus(String(o.messages.waiting || ''));
      ensureToken().then(waitUntilAccepted).then(function () {
        holding = false;
        if (o.submitBtn) { o.submitBtn.removeAttribute('aria-disabled'); }
        return fetchRecaptchaToken(function (rcToken) {
          setBusy(true);
          return send(rcToken);
        });
      });
    };

    var submitWithRecaptcha = function () {
      // Busy only once the token is in hand: closing the invisible challenge fires no callback.
      fetchRecaptchaToken(function (rcToken) {
        setBusy(true);
        return ensureToken().then(function () {
          if (remainingWait() > LONG_WAIT_MS) {
            // reCAPTCHA tokens live 2 minutes: ask for a fresh one after the wait.
            resetRecaptcha();
            setBusy(false);
            submitAfterLongWait();
            return null;
          }
          return waitUntilAccepted().then(function () { return send(rcToken); });
        });
      });
    };

    return {
      ensureToken: ensureToken,
      applySecrets: applySecrets,
      isBusy: function () { return submitting || holding; },
      // Runs once the form is valid and the visitor's own `ffb:submit` handlers allowed it.
      start: function () {
        if (!o.recaptcha) {
          setBusy(true);
          ensureToken().then(waitUntilAccepted).then(function () { return send(); });
        } else if (remainingWait() > LONG_WAIT_MS) {
          submitAfterLongWait();
        } else {
          submitWithRecaptcha();
        }
      }
    };
  };

  ns.submit = { create: create, resolveFormKey: resolveFormKey };
})(typeof globalThis !== 'undefined' ? globalThis : this);
