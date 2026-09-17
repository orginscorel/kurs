'use strict';

/** GET /gateway/identities ile aktif eşlemeleri periyodik çeker, yerel önbelleğe yazar. */
function createIdentitySync({ queue, config, logger }) {
  let timer = null;

  async function syncOnce() {
    try {
      const res = await fetch(`${config.apiBaseUrl}/gateway/identities`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${config.deviceToken}` },
      });
      if (!res.ok) {
        logger.warn(`kimlik senkronu başarısız (HTTP ${res.status})`);
        return;
      }
      const body = await res.json();
      queue.upsertIdentities(body.data || []);
      logger.info(`${(body.data || []).length} kimlik eşlemesi senkronize edildi`);
    } catch (err) {
      logger.warn('kimlik senkronu ağ hatası', err.message);
    }
  }

  return {
    start() {
      syncOnce();
      timer = setInterval(syncOnce, config.identitySyncIntervalMs);
      timer.unref?.();
    },
    stop() {
      if (timer) clearInterval(timer);
    },
    syncOnce,
  };
}

module.exports = { createIdentitySync };
