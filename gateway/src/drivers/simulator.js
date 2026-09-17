'use strict';

const crypto = require('crypto');

/**
 * Test/geliştirme sürücüsü: gerçek donanım olmadan olay üretir.
 * config.simulator.autoIntervalMs > 0 ise periyodik rastgele okutma üretir
 * (config.simulator.identities listesinden). Gerçek uçtan uca testler için
 * `npm run simulate -- identifier=1001,kind=fingerprint,type=ENTRY` kullanılır
 * (bkz. src/index.js — bu, sürücüyü hiç başlatmadan tek seferlik olay enjekte eder).
 */
function createSimulatorDriver({ config, logger, onEvent }) {
  let timer = null;
  const identities = config.simulator?.identities || [];
  const intervalMs = config.simulator?.autoIntervalMs || 0;

  function emitRandom() {
    if (identities.length === 0) return;
    const identity = identities[Math.floor(Math.random() * identities.length)];
    onEvent({
      identifier: identity.identifier,
      identifier_kind: identity.identifier_kind || 'fingerprint',
      event_type: 'AUTO',
      occurred_at: new Date().toISOString(),
      idempotency_key: `sim-${identity.identifier}-${Date.now()}-${crypto.randomBytes(3).toString('hex')}`,
    });
  }

  return {
    start() {
      if (intervalMs > 0) {
        logger.info(`simülatör: her ${intervalMs}ms'de bir rastgele okutma üretilecek (${identities.length} kimlik)`);
        timer = setInterval(emitRandom, intervalMs);
        timer.unref?.();
      } else {
        logger.info('simülatör: otomatik üretim kapalı (simulator.autoIntervalMs=0). Manuel test için "npm run simulate" kullanın.');
      }
    },
    stop() {
      if (timer) clearInterval(timer);
    },
  };
}

module.exports = { createSimulatorDriver };
