'use strict';

const fs = require('fs');
const path = require('path');

const CONFIG_PATH = path.join(__dirname, '..', 'config.json');
const EXAMPLE_PATH = path.join(__dirname, '..', 'config.example.json');

function loadConfig() {
  if (!fs.existsSync(CONFIG_PATH)) {
    console.error(
      `[config] config.json bulunamadı. Örneği kopyalayın:\n  cp "${EXAMPLE_PATH}" "${CONFIG_PATH}"\nardından apiBaseUrl ve deviceToken alanlarını doldurun.`,
    );
    process.exit(1);
  }

  const raw = fs.readFileSync(CONFIG_PATH, 'utf8');
  let config;
  try {
    config = JSON.parse(raw);
  } catch (err) {
    console.error(`[config] config.json geçersiz JSON: ${err.message}`);
    process.exit(1);
  }

  if (!config.apiBaseUrl || !config.deviceToken || config.deviceToken.includes('BURAYA')) {
    console.error('[config] apiBaseUrl ve deviceToken zorunludur (config.json).');
    process.exit(1);
  }

  // Göreli yolları config.json'a göre çöz.
  config.queue = config.queue || {};
  config.queue.dbPath = path.resolve(path.dirname(CONFIG_PATH), config.queue.dbPath || './data/queue.sqlite3');

  config.driver = config.driver || 'simulator';
  config.heartbeatIntervalMs = config.heartbeatIntervalMs || 60000;
  config.identitySyncIntervalMs = config.identitySyncIntervalMs || 300000;
  config.queue.batchSize = config.queue.batchSize || 100;
  config.queue.flushIntervalMs = config.queue.flushIntervalMs || 5000;
  config.queue.maxBackoffMs = config.queue.maxBackoffMs || 300000;

  return config;
}

module.exports = { loadConfig, CONFIG_PATH, EXAMPLE_PATH };
