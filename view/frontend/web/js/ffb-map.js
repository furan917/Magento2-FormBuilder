(function (root) {
  'use strict';

  const DEFAULT_ZOOM = 13;
  const REFERRER_POLICY = 'strict-origin-when-cross-origin';

  function format(template, args) {
    return String(template).replace(/%(\d)/g, (m, i) => (args[i - 1] !== undefined ? args[i - 1] : m));
  }

  function toNumber(el, fallback) {
    const n = parseFloat(el.getAttribute('data-' + fallback.attr));
    return Number.isFinite(n) ? n : fallback.value;
  }

  // Shared by both providers: owns the coordinate/label inputs, status line and form reset.
  function createIo(wrapper, messages) {
    const q = (s) => wrapper.querySelector(s);
    const latInput = q('[data-ffb-map-lat]');
    const lngInput = q('[data-ffb-map-lng]');
    const zoomInput = q('[data-ffb-map-zoom]');
    const labelInput = q('[data-ffb-map-label]');
    const status = q('[data-ffb-map-status]');
    const form = wrapper.closest('form');
    let writing = false;

    function notify(el) {
      ['input', 'change'].forEach((type) => el.dispatchEvent(new root.Event(type, { bubbles: true })));
    }

    return {
      write(lat, lng, zoom, label) {
        writing = true;
        latInput.value = lat.toFixed(6);
        lngInput.value = lng.toFixed(6);
        if (zoomInput) {
          zoomInput.value = String(zoom);
        }
        if (labelInput && label !== undefined) {
          labelInput.value = label;
        }
        [latInput, lngInput].forEach(notify);
        writing = false;
        status.textContent = format(messages.pinSet || 'Pin set at %1, %2', [latInput.value, lngInput.value]);
      },
      setZoom(zoom) {
        if (zoomInput) {
          zoomInput.value = String(zoom);
        }
      },
      read() {
        if (writing || latInput.value.trim() === '' || lngInput.value.trim() === '') {
          return null;
        }
        const lat = Number(latInput.value);
        const lng = Number(lngInput.value);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) {
          return null;
        }
        return { lat, lng };
      },
      onInputsChange(handler) {
        [latInput, lngInput].forEach((el) => el.addEventListener('change', handler));
      },
      onReset(handler) {
        if (form) {
          form.addEventListener('reset', () => {
            status.textContent = '';
            handler();
          });
        }
      },
    };
  }

  const SEARCH_MIN_LENGTH = 3;
  const SEARCH_ZOOM = 16;

  // Explicit button/Enter only (Nominatim forbids search-as-you-type); one request at a time.
  function initSearch(wrapper, maps, io, pick) {
    const box = wrapper.querySelector('[data-ffb-map-search]');
    if (!box || !maps.searchUrl) {
      return;
    }
    const input = box.querySelector('[data-ffb-map-search-input]');
    const button = box.querySelector('[data-ffb-map-search-button]');
    const list = box.querySelector('[data-ffb-map-search-results]');
    const status = box.querySelector('[data-ffb-map-search-status]');
    const formId = wrapper.closest('form').querySelector('input[name="form_id"]');
    const core = (root.FuranFormBuilder || {}).core;
    let pending = false;

    const say = (key) => {
      status.textContent = box.getAttribute('data-' + key) || '';
    };

    function clearResults() {
      list.hidden = true;
      while (list.firstChild) {
        list.removeChild(list.firstChild);
      }
    }

    function choose(result) {
      const value = core.normalizeValue({ type: 'map' }, { lat: result.lat, lng: result.lon, label: result.label });
      if (!value || value.error) {
        say('error');
        return;
      }
      pick(+value.lat, +value.lng, value.label);
      clearResults();
    }

    function render(results) {
      clearResults();
      if (!Array.isArray(results) || results.length === 0) {
        say('empty');
        return;
      }
      status.textContent = '';
      results.forEach((result) => {
        const item = root.document.createElement('li');
        const choice = root.document.createElement('button');
        choice.type = 'button';
        choice.className = 'ffb-btn ffb-btn-secondary';
        choice.textContent = String(result.label);
        choice.addEventListener('click', () => choose(result));
        item.appendChild(choice);
        list.appendChild(item);
      });
      list.hidden = false;
    }

    function search() {
      const query = input.value.trim();
      if (pending) {
        return;
      }
      clearResults();
      if (Array.from(query).length < SEARCH_MIN_LENGTH) {
        say('short');
        return;
      }
      pending = true;
      button.disabled = true;
      status.textContent = '';
      const params = new root.URLSearchParams({ form_id: formId ? formId.value : '', q: query });
      const url = maps.searchUrl + (maps.searchUrl.indexOf('?') === -1 ? '?' : '&') + params.toString();
      root.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then((response) => {
          if (response.status === 429) {
            say('busy');
            return null;
          }
          if (!response.ok) {
            throw new Error('search failed');
          }
          return response.json().then(render);
        })
        .catch(() => say('error'))
        .then(() => {
          pending = false;
          button.disabled = false;
        });
    }

    box.hidden = false;
    button.addEventListener('click', search);
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        search();
      }
    });
    io.onReset(() => {
      clearResults();
      status.textContent = '';
      input.value = '';
    });
  }

  let adapterPromise = null;
  function loadGoogleAdapter(url) {
    const ns = root.FuranFormBuilder = root.FuranFormBuilder || {};
    if (ns.mapGoogle) {
      return Promise.resolve(ns.mapGoogle);
    }
    if (!adapterPromise) {
      adapterPromise = new Promise((resolve, reject) => {
        const script = root.document.createElement('script');
        script.src = url;
        script.addEventListener('load', () => resolve(ns.mapGoogle));
        script.addEventListener('error', () => reject(new Error('Map adapter failed to load')));
        root.document.head.appendChild(script);
      }).catch((error) => {
        adapterPromise = null;
        throw error;
      });
    }
    return adapterPromise;
  }

  function initGoogle(wrapper, maps) {
    const messages = maps.messages || {};
    const canvas = wrapper.querySelector('[data-ffb-map-canvas]');
    const status = wrapper.querySelector('[data-ffb-map-status]');
    const handle = { invalidateSize() {}, ready: Promise.resolve() };
    if (!maps.googleKey) {
      canvas.hidden = true;
      return handle;
    }
    const io = createIo(wrapper, messages);
    handle.ready = loadGoogleAdapter((maps.assets || {}).googleJs)
      .then((adapter) => adapter.init(wrapper, maps, io))
      .then((adapterHandle) => {
        handle.invalidateSize = adapterHandle.invalidateSize;
      })
      .catch(() => {
        status.textContent = String(messages.loadError || '');
      });
    return handle;
  }

  function init(wrapper, config) {
    const L = root.L;
    const maps = config || {};
    if (maps.provider === 'google') {
      return initGoogle(wrapper, maps);
    }
    const assets = maps.assets || {};
    const messages = maps.messages || {};
    const q = (s) => wrapper.querySelector(s);
    const canvas = q('[data-ffb-map-canvas]');
    const io = createIo(wrapper, messages);

    if (assets.imagePath) {
      L.Icon.Default.imagePath = assets.imagePath;
    }

    const centre = [toNumber(wrapper, { attr: 'center-lat', value: 0 }), toNumber(wrapper, { attr: 'center-lng', value: 0 })];
    const zoom = toNumber(wrapper, { attr: 'zoom', value: DEFAULT_ZOOM });
    const map = L.map(canvas, { attributionControl: true }).setView(centre, zoom);
    L.tileLayer(maps.tileUrl, {
      attribution: maps.attribution || '',
      maxZoom: maps.maxZoom,
      referrerPolicy: REFERRER_POLICY,
    }).addTo(map);

    let marker = null;

    function writeInputs(latlng) {
      const p = latlng.wrap();
      io.write(p.lat, p.lng, map.getZoom());
    }

    function place(latlng) {
      const pos = [latlng.lat, latlng.lng];
      if (marker) {
        marker.setLatLng(pos);
      } else {
        marker = L.marker(pos, { draggable: true, keyboard: true }).addTo(map);
        marker.on('dragend', (e) => writeInputs(e.target.getLatLng()));
      }
    }

    function fromInputs() {
      const point = io.read();
      if (!point) {
        return;
      }
      place(point);
      map.setView([point.lat, point.lng], map.getZoom());
    }

    map.on('click', (e) => {
      place(e.latlng);
      writeInputs(e.latlng);
    });
    map.on('zoomend', () => {
      if (marker) {
        io.setZoom(map.getZoom());
      }
    });
    io.onInputsChange(fromInputs);
    io.onReset(() => {
      if (marker) {
        marker.remove();
        marker = null;
      }
    });
    const centreButton = q('[data-ffb-map-centre]');
    if (centreButton) {
      centreButton.addEventListener('click', () => {
        const c = map.getCenter();
        place(c);
        writeInputs(c);
      });
    }

    initSearch(wrapper, maps, io, (lat, lng, label) => {
      place({ lat, lng });
      map.setView([lat, lng], Math.max(map.getZoom(), SEARCH_ZOOM));
      io.write(lat, lng, map.getZoom(), label);
    });

    fromInputs();

    return { map, invalidateSize: () => map.invalidateSize() };
  }

  const api = { init, createIo };
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).map = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
