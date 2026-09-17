'use strict';

/**
 * Arka planda toplu gönderim: kuyruktaki olayları POST /gateway/events ile sunucuya
 * teslim eder. Ağ hatasında üstel geri çekilme (exponential backoff) uygular;
 * sunucu bir olayı işlediyse (accepted/duplicate/unmatched/debounced fark etmez)
 * o olay yerel kuyruktan silinir — çift kayıt oluşmaz.
 */
function createSender({ queue, config, logger }) {
  let backoffMs = config.queue.flushIntervalMs;
  let timer = null;
  let stopped = false;
  let inFlight = false;

  function toPayload(row) {
    return {
      idempotency_key: row.idempotency_key,
      identifier: row.identifier || undefined,
      identifier_kind: row.identifier_kind || undefined,
      student_id: row.student_id || undefined,
      event_type: row.event_type,
      occurred_at: row.occurred_at,
    };
  }

  async function postEvents(events) {
    const res = await fetch(`${config.apiBaseUrl}/gateway/events`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${config.deviceToken}` },
      body: JSON.stringify({ events }),
    });

    const text = await res.text();
    let body = {};
    try {
      body = text ? JSON.parse(text) : {};
    } catch {
      /* boş/HTML yanıt — aşağıda status kontrolü hatayı yakalar */
    }

    return { ok: res.ok, status: res.status, body };
  }

  /** Toplu gönderim 422 (doğrulama) hatası verirse tek tek gönderip bozuk olanı ayıklar. */
  async function sendOneByOne(rows) {
    const processedKeys = [];
    for (const row of rows) {
      try {
        const { ok, body } = await postEvents([toPayload(row)]);
        if (ok) {
          processedKeys.push(row.idempotency_key);
        } else {
          queue.markAttemptFailed(row.idempotency_key, JSON.stringify(body).slice(0, 400));
        }
      } catch (err) {
        // Ağ hatası tekil denemede de sürüyor: bu satırı bu turda atla, kuyrukta kalsın.
        logger.warn('tekil gönderim ağ hatası', row.idempotency_key, err.message);
      }
    }
    return processedKeys;
  }

  async function flushOnce() {
    if (inFlight) return { sent: 0, skipped: true };
    inFlight = true;
    try {
      const batch = queue.nextBatch(config.queue.batchSize);
      if (batch.length === 0) return { sent: 0 };

      const { ok, status, body } = await postEvents(batch.map(toPayload));

      if (ok) {
        const keys = (body.results || []).map((r) => r.idempotency_key).filter(Boolean);
        queue.removeProcessed(keys.length ? keys : batch.map((r) => r.idempotency_key));
        backoffMs = config.queue.flushIntervalMs;
        logger.info(`${keys.length || batch.length} olay gönderildi`, body.summary || '');
        return { sent: keys.length || batch.length };
      }

      if (status === 422 && batch.length > 1) {
        logger.warn(`toplu gönderim doğrulama hatası (422), tek tek deneniyor (${batch.length} olay)`);
        const processed = await sendOneByOne(batch);
        if (processed.length) queue.removeProcessed(processed);
        backoffMs = config.queue.flushIntervalMs;
        return { sent: processed.length };
      }

      logger.warn(`sunucu hatası (HTTP ${status})`, JSON.stringify(body).slice(0, 300));
      backoffMs = Math.min(backoffMs * 2, config.queue.maxBackoffMs);
      return { sent: 0, error: `http_${status}` };
    } catch (err) {
      logger.warn('gönderim ağ hatası, kuyrukta bekletiliyor', err.message);
      backoffMs = Math.min(backoffMs * 2, config.queue.maxBackoffMs);
      return { sent: 0, error: err.message };
    } finally {
      inFlight = false;
    }
  }

  function scheduleNext(delay) {
    if (stopped) return;
    timer = setTimeout(loop, delay);
    timer.unref?.();
  }

  async function loop() {
    const result = await flushOnce();
    const size = queue.queueSize();
    if (size > 0 && result.error) {
      logger.info(`kuyrukta ${size} bekleyen olay var, ${Math.round(backoffMs / 1000)} sn sonra tekrar denenecek`);
    }
    scheduleNext(result.error ? backoffMs : config.queue.flushIntervalMs);
  }

  return {
    start() {
      stopped = false;
      scheduleNext(0);
    },
    stop() {
      stopped = true;
      if (timer) clearTimeout(timer);
    },
    flushOnce,
  };
}

module.exports = { createSender };
