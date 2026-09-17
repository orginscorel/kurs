'use strict';

const LEVELS = { error: 0, warn: 1, info: 2, debug: 3 };

function createLogger(level = 'info') {
  const threshold = LEVELS[level] ?? LEVELS.info;

  function log(lvl, ...args) {
    if (LEVELS[lvl] > threshold) return;
    const ts = new Date().toISOString();
    const fn = lvl === 'error' ? console.error : lvl === 'warn' ? console.warn : console.log;
    fn(`[${ts}] [${lvl.toUpperCase()}]`, ...args);
  }

  return {
    error: (...a) => log('error', ...a),
    warn: (...a) => log('warn', ...a),
    info: (...a) => log('info', ...a),
    debug: (...a) => log('debug', ...a),
  };
}

module.exports = { createLogger };
