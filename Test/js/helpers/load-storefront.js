'use strict';
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../../..');
const jsDir = path.join(root, 'view/frontend/web/js');

// Same order form.phtml prints them: core, built-in types, drafts, submit, extension scripts, form.
const CORE = 'ffb-core.js';
const BEFORE_EXTENSIONS = [CORE, 'ffb-types.js', 'ffb-drafts.js', 'ffb-submit.js'];
const AFTER_EXTENSIONS = ['ffb-form.js'];

const source = (name) => fs.readFileSync(path.join(jsDir, name), 'utf8');

// extensions: sources (strings) or functions of the window, run between the built-in scripts and ffb-form.js.
function loadStorefront(win, extensions = []) {
  BEFORE_EXTENSIONS.forEach((name) => win.eval(source(name)));
  extensions.forEach((ext) => (typeof ext === 'function' ? ext(win) : win.eval(ext)));
  AFTER_EXTENSIONS.forEach((name) => win.eval(source(name)));
}

function loadCore(win) {
  win.eval(source(CORE));
}

module.exports = {
  loadStorefront,
  loadCore,
  source,
  corePath: path.join(jsDir, CORE),
  get core() { return require(path.join(jsDir, CORE)); },
  scriptOrder: () => [...BEFORE_EXTENSIONS, '<extensions>', ...AFTER_EXTENSIONS],
};
