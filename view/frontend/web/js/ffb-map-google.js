(function (root) {
  'use strict';

  const DEFAULT_ZOOM = 13;
  const LIBRARIES = ['maps', 'marker', 'places'];
  const PLACE_FIELDS = ['location', 'formattedAddress'];

  // Google's dynamic-import bootstrap loader, kept in a static file so no inline script is needed.
  function bootstrap(config) {
    const google = root.google = root.google || {};
    const maps = google.maps = google.maps || {};
    if (maps.importLibrary) {
      return;
    }
    let loading = null;
    const load = () => {
      if (!loading) {
        loading = new Promise((resolve, reject) => {
          const [language, region] = String(config.locale || '').split(/[_-]/);
          const params = new root.URLSearchParams({
            key: config.googleKey,
            v: 'weekly',
            loading: 'async',
            libraries: LIBRARIES.join(','),
            callback: 'google.maps.__ib__',
          });
          if (language) {
            params.set('language', language);
          }
          if (region) {
            params.set('region', region);
          }
          maps.__ib__ = resolve;
          const script = root.document.createElement('script');
          script.async = true;
          script.src = 'https://maps.googleapis.com/maps/api/js?' + params.toString();
          script.addEventListener('error', () => {
            loading = null;
            reject(new Error('Google Maps failed to load'));
          });
          root.document.head.appendChild(script);
        });
      }
      return loading;
    };
    maps.importLibrary = (name) => load().then(() => maps.importLibrary(name));
  }

  const coordinate = (value) => (typeof value === 'function' ? value() : value);
  const toPoint = (position) => ({ lat: coordinate(position.lat), lng: coordinate(position.lng) });

  function wrapLng(lng) {
    return ((lng + 180) % 360 + 360) % 360 - 180;
  }

  function createMap(wrapper, config, io, { Map, AdvancedMarkerElement, PlaceAutocompleteElement }) {
    const q = (s) => wrapper.querySelector(s);
    const canvas = q('[data-ffb-map-canvas]');
    const centreAttr = (name, fallback) => {
      const n = parseFloat(wrapper.getAttribute('data-' + name));
      return Number.isFinite(n) ? n : fallback;
    };
    const map = new Map(canvas, {
      center: { lat: centreAttr('center-lat', 0), lng: centreAttr('center-lng', 0) },
      zoom: centreAttr('zoom', DEFAULT_ZOOM),
      mapId: config.mapId,
    });
    let marker = null;

    function place(point) {
      const position = { lat: point.lat, lng: point.lng };
      if (marker) {
        marker.position = position;
        return;
      }
      marker = new AdvancedMarkerElement({
        map,
        position,
        gmpDraggable: true,
        title: canvas.getAttribute('aria-label') || '',
      });
      marker.addEventListener('gmp-dragend', () => {
        const p = toPoint(marker.position);
        io.write(p.lat, wrapLng(p.lng), map.getZoom(), '');
      });
    }

    function pinAt(point, label) {
      place(point);
      io.write(point.lat, wrapLng(point.lng), map.getZoom(), label);
    }

    map.addListener('click', (e) => pinAt(toPoint(e.latLng), ''));
    io.onInputsChange(() => {
      const point = io.read();
      if (point) {
        place(point);
        map.setCenter(point);
      }
    });
    io.onReset(() => {
      if (marker) {
        marker.map = null;
        marker = null;
      }
    });
    const centreButton = q('[data-ffb-map-centre]');
    if (centreButton) {
      centreButton.addEventListener('click', () => pinAt(toPoint(map.getCenter()), ''));
    }

    const search = new PlaceAutocompleteElement({});
    canvas.parentNode.insertBefore(search, canvas);
    search.addEventListener('gmp-select', (event) => {
      const selected = event.placePrediction.toPlace();
      selected.fetchFields({ fields: PLACE_FIELDS }).then(() => {
        const point = toPoint(selected.location);
        pinAt(point, String(selected.formattedAddress || ''));
        map.setCenter(point);
      });
    });

    const start = io.read();
    if (start) {
      place(start);
      map.setCenter(start);
    }

    return {
      invalidateSize() {
        const events = root.google.maps.event;
        if (events) {
          events.trigger(map, 'resize');
        }
      },
    };
  }

  function init(wrapper, config, io) {
    bootstrap(config);
    const maps = root.google.maps;
    return Promise.all(LIBRARIES.map((name) => maps.importLibrary(name)))
      .then(([mapsLib, markerLib, placesLib]) => createMap(wrapper, config, io, {
        Map: mapsLib.Map,
        AdvancedMarkerElement: markerLib.AdvancedMarkerElement,
        PlaceAutocompleteElement: placesLib.PlaceAutocompleteElement,
      }));
  }

  const api = { init };
  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).mapGoogle = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
