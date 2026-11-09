'use strict';
const path = require('node:path');
const { JSDOM } = require(process.env.FFB_JSDOM || path.resolve(__dirname, '../../../../../../../dev/furan-tools/node/node_modules/jsdom'));
module.exports = { JSDOM };
