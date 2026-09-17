#!/usr/bin/env node
'use strict';

const crypto = require('crypto');
const { loadConfig } = require('./config');
const { createLogger } = require('./logger');
const { openQueue } = require('./db');
const { createSender } = require('./sender');
const { createIdentitySync } = require('./identities');
const { createHeartbeat } = require('./heartbeat');
const { createSimulatorDriver } = require('./drivers/simulator');
const { createZktecoDriver } = require('./drivers/zkteco');
const { createSerialRfidDriver } = require('./drivers/serial-rfid');

function parseKeyValueArgs(args) {
  const out = {};
  for (const arg of args) {
    for (const pair of arg.split(',')) {
      const [k, v] = pair.split('=');
      if (k && v !== undefined) out[k.trim()] = v.trim();
    }
  }
  return out;
}

function pickDriver(name, deps) {
  switch (name) {
    case 'zkteco':
      return createZktecoDriver(deps);
    case 'serial-rfid':
      return createSerialRfidDriver(deps);
    case 'simulator':
    default:
      return createSimulatorDriver(deps);
  }
}

async function runSimulateOnce(config, logger, args) {
  const opts = parseKeyValueArgs(args);
  const queue = openQueue(config.queue.dbPath, logger);
  const sender = createSender({ queue, config, logger });

  const event = {
    identifier: opts.identifier,
    identifier_kind: opts.kind || 'fingerprint',
    student_id: opts.student_id ? Number(opts.student_id) : undefined,
    event_type: (opts.type || 'AUTO').toUpperCase(),
    occurred_at: opts.occurred_at || new Date().toISOString(),
    idempotency_key: opts.key || `manual-sim-${Date.now()}-${crypto.randomBytes(3).toString('hex')}`,
  };

  if (!event.identifier && !event.student_id) {
    console.error('Kullanım: npm run simulate -- identifier=1001,kind=fingerprint,type=ENTRY');
    console.error('          npm run simulate -- student_id=42,type=EXIT');
    process.exit(1);
  }

  logger.info('Tek seferlik test olayı enjekte ediliyor:', event);
  queue.enqueue(event);

  // Kısa bir üstel geri çekilme ile birkaç deneme (çevrimdışıysa kuyrukta kalır, komut yine de sonlanır).
  let result = await sender.flushOnce();
  for (let i = 0; i < 2 && result.error; i++) {
    await new Promise((r) => setTimeout(r, 1500));
    result = await sender.flushOnce();
  }

  console.log('Sonuç:', JSON.stringify(result));
  console.log('Kalan kuyruk boyutu:', queue.queueSize());
  queue.close();
  process.exit(result.error ? 2 : 0);
}

function runDaemon(config, logger) {
  const queue = openQueue(config.queue.dbPath, logger);
  const sender = createSender({ queue, config, logger });
  const identitySync = createIdentitySync({ queue, config, logger });
  const heartbeat = createHeartbeat({ queue, config, logger });

  const driver = pickDriver(config.driver, {
    config,
    logger,
    onEvent(event) {
      queue.enqueue({ ...event, idempotency_key: event.idempotency_key || crypto.randomUUID() });
    },
  });

  logger.info(`Yoklama köprüsü başlatıldı — sürücü: ${config.driver}, API: ${config.apiBaseUrl}`);
  driver.start();
  sender.start();
  heartbeat.start();
  identitySync.start();

  let shuttingDown = false;
  function shutdown(signal) {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info(`${signal} alındı, kapatılıyor…`);
    driver.stop();
    sender.stop();
    heartbeat.stop();
    identitySync.stop();
    queue.close();
    process.exit(0);
  }

  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));
}

function main() {
  const argv = process.argv.slice(2);
  const config = loadConfig();
  const logger = createLogger(config.logLevel);

  if (argv[0] === '--simulate') {
    runSimulateOnce(config, logger, argv.slice(1)).catch((err) => {
      logger.error('simülasyon hatası', err);
      process.exit(1);
    });
    return;
  }

  runDaemon(config, logger);
}

main();
