'use strict';

const pkg = require('../package.json');

/** POST /gateway/heartbeat — cihazın "çevrim içi" sayılması için last_seen_at günceller. */
function createHeartbeat({ queue, config, logger }) {
  let timer = null;

  async function beatOnce() {
    try {
      const res = await fetch(`${config.apiBaseUrl}/gateway/heartbeat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${config.deviceToken}` },
        body: JSON.stringify({ firmware: `gateway ${pkg.version}`, queue_size: queue.queueSize() }),
      });
      if (!res.ok) logger.warn(`heartbeat başarısız (HTTP ${res.status})`);
    } catch (err) {
      logger.warn('heartbeat ağ hatası', err.message);
    }
  }

  return {
    start() {
      beatOnce();
      timer = setInterval(beatOnce, config.heartbeatIntervalMs);
      timer.unref?.();
    },
    stop() {
      if (timer) clearInterval(timer);
    },
    beatOnce,
  };
}

module.exports = { createHeartbeat };
