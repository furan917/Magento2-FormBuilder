(function (root) {
  'use strict';

  function tr(text, ...args) {
    const api = typeof module === 'object' && module.exports
      ? require('./builder-i18n.js')
      : (root.FuranFormBuilder || {}).builderI18n;
    return api
      ? api.t(text, ...args)
      : String(text).replace(/%(\d+)/g, (m, n) => (args[n - 1] !== undefined ? String(args[n - 1]) : m));
  }

  // Marks text for the PHP phrase list; it is translated where it is shown.
  const phrase = (text) => text;

  // Each preset builds items from existing field types; `needs` lists the type codes it requires.
  const DEFINITIONS = [
    {
      code: 'full_name', label: phrase('Full name'), needs: ['text'],
      build: (f) => [
        f('text', { label: tr('First name'), name: 'first_name', width: 'half', required: true, autocomplete: 'given-name' }),
        f('text', { label: tr('Last name'), name: 'last_name', width: 'half', required: true, autocomplete: 'family-name' }),
      ],
    },
    {
      code: 'email_confirm', label: phrase('Email + confirm'), needs: ['email'],
      build: (f) => {
        const email = f('email', { label: tr('Email'), name: 'email', required: true, autocomplete: 'email' });
        const confirm = f('email', { label: tr('Confirm email'), name: 'confirm_email', required: true, autocomplete: 'email' });
        confirm.rules = [{ rule: 'matches', value: email.name, message: '' }];
        return [email, confirm];
      },
    },
    {
      code: 'phone', label: phrase('Phone'), needs: ['tel'],
      build: (f) => [f('tel', { label: tr('Phone'), name: 'phone', autocomplete: 'tel' })],
    },
    {
      code: 'address', label: phrase('Address'), hasSection: true, needs: ['text', 'country'],
      build: (f, newSection) => {
        const section = newSection();
        section.title = tr('Address');
        section.items = [
          f('text', { label: tr('Street address'), name: 'street', required: true, autocomplete: 'street-address' }),
          f('text', { label: tr('City'), name: 'city', width: 'half', required: true, autocomplete: 'address-level2' }),
          f('text', { label: tr('Postcode'), name: 'postcode', width: 'half', required: true, autocomplete: 'postal-code' }),
          f('country', { label: tr('Country'), name: 'country', required: true, autocomplete: 'country' }),
        ];
        return [section];
      },
    },
    {
      code: 'yes_no', label: phrase('Yes / No'), needs: ['radio'],
      build: (f) => [f('radio', {
        label: tr('Yes or no?'), name: 'yes_no', settings: { layout: 'inline' },
        options: [{ label: tr('Yes'), value: 'yes' }, { label: tr('No'), value: 'no' }],
      })],
    },
    {
      code: 'date_of_birth', label: phrase('Date of birth'), needs: ['date'],
      build: (f) => [f('date', { label: tr('Date of birth'), name: 'date_of_birth', autocomplete: 'bday' })],
    },
    {
      code: 'nps', label: phrase('NPS 0-10'), needs: ['scale'],
      build: (f) => [f('scale', {
        label: tr('How likely are you to recommend us?'), name: 'nps', required: true,
        settings: { min: 0, max: 10, low_label: tr('Not likely'), high_label: tr('Very likely') },
      })],
    },
    {
      code: 'terms_consent', label: phrase('Terms consent'), needs: ['consent'],
      build: (f) => [f('consent', { label: tr('I agree to the terms and conditions'), name: 'terms', required: true })],
    },
  ];

  function available(config) {
    const codes = new Set(((config && config.fieldTypes) || []).map((t) => t.code));
    return DEFINITIONS.filter((p) => p.needs.every((c) => codes.has(c)));
  }

  // Returns fresh items with names unique against the state and each other.
  function build(code, model, config, state) {
    const def = DEFINITIONS.find((p) => p.code === code);
    if (!def || !available(config).includes(def)) {
      return null;
    }
    const taken = model.allFields(state).map((x) => x.name);
    const make = (type, patch) => {
      const item = model.newField(type, config, state);
      Object.keys(patch).forEach((key) => {
        item[key] = key === 'settings' ? Object.assign({}, item.settings, patch.settings) : patch[key];
      });
      item.name = model.uniqueName(patch.name || item.name, taken);
      taken.push(item.name);
      return item;
    };
    return def.build(make, model.newSection);
  }

  const api = { available, build, DEFINITIONS };

  if (typeof module === 'object' && module.exports) {
    module.exports = api;
  } else {
    (root.FuranFormBuilder = root.FuranFormBuilder || {}).builderPresets = api;
  }
}(typeof window !== 'undefined' ? window : globalThis));
