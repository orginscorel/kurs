'use strict';

const net = require('net');
const crypto = require('crypto');

/*
 * ZKTeco TCP (4370) sürücüsü — açık kaynak zklib/zkteco-js projelerinde yaygın
 * kullanılan protokol çerçevesine göre yazılmıştır (node-zklib tarzı arayüz).
 *
 * ÖNEMLİ: Bu sürücü gerçek bir ZKTeco cihazına karşı test EDİLEMEDİ (bu ortamda
 * fiziksel cihaz yok). Bayt düzeni firmware'e göre küçük farklılıklar gösterebilir;
 * sahada ilk kurulumda `logLevel: "debug"` ile ham paketleri (hex) loglayıp
 * `parseRealTimeLog()` fonksiyonunu gerekirse cihazın el kitabına göre ayarlayın.
 *
 * Akış:
 *  1) TCP bağlantısı açılır, CMD_CONNECT (1000) gönderilir → cihaz bir session_id döner.
 *  2) CMD_REG_EVENT (500) ile gerçek zamanlı olay aboneliği başlatılır.
 *  3) Cihaz her parmak izi/kart okutmasında istemsiz (unsolicited) bir paket gönderir;
 *     bu paket ayrıştırılıp onEvent() çağrılır.
 *  4) Bağlantı koparsa üstel geri çekilme ile yeniden bağlanılır.
 */

const CMD_CONNECT = 1000;
const CMD_EXIT = 1001;
const CMD_REG_EVENT = 500;
const CMD_ACK_OK = 2000;
const TCP_MAGIC = Buffer.from([0x50, 0x50, 0x82, 0x7d]);

function checksum16(buf) {
  let sum = 0;
  for (let i = 0; i < buf.length; i += 2) {
    const word = i + 1 < buf.length ? buf.readUInt16LE(i) : buf.readUInt8(i);
    sum += word;
  }
  while (sum > 0xffff) sum = (sum & 0xffff) + (sum >> 16);
  return ~sum & 0xffff;
}

function buildPacket(command, sessionId, replyId, data = Buffer.alloc(0)) {
  const header = Buffer.alloc(8);
  header.writeUInt16LE(command, 0);
  header.writeUInt16LE(0, 2); // checksum yer tutucu
  header.writeUInt16LE(sessionId, 4);
  header.writeUInt16LE(replyId, 6);
  const payload = Buffer.concat([header, data]);
  payload.writeUInt16LE(checksum16(payload), 2);

  const wrapped = Buffer.alloc(8 + payload.length);
  TCP_MAGIC.copy(wrapped, 0);
  wrapped.writeUInt32LE(payload.length, 4);
  payload.copy(wrapped, 8);
  return wrapped;
}

/** Cihazın kompakt zaman kodunu Date'e çevirir (klasik ZK algoritması). */
function decodeZkTime(value) {
  let t = value;
  const second = t % 60; t = Math.floor(t / 60);
  const minute = t % 60; t = Math.floor(t / 60);
  const hour = t % 24; t = Math.floor(t / 24);
  const day = (t % 31) + 1; t = Math.floor(t / 31);
  const month = t % 12; t = Math.floor(t / 12);
  const year = t + 2000;
  return new Date(year, month, day, hour, minute, second);
}

/**
 * Gerçek zamanlı olay verisini ayrıştırır. Yaygın biçim: ilk ~9-24 bayt kullanıcı
 * kimliği (ASCII, NUL dolgulu), ardından doğrulama tipi, giriş/çıkış modu ve
 * 4 baytlık zaman kodu içerir. Firmware'e göre alan uzunlukları değişebilir.
 */
function parseRealTimeLog(data, logger) {
  try {
    if (data.length < 12) return null;

    // Kullanıcı kimliği: NUL byte'a kadar olan ASCII kısmı (ilk 9-24 bayt aralığında ara).
    const idFieldLength = Math.min(24, data.length - 8);
    let userIdRaw = data.subarray(0, idFieldLength);
    const nul = userIdRaw.indexOf(0);
    if (nul > 0) userIdRaw = userIdRaw.subarray(0, nul);
    const userId = userIdRaw.toString('ascii').replace(/[^\x20-\x7e]/g, '').trim();

    // Zaman kodu: paketin son 4 baytı (yaygın yerleşim).
    const timeValue = data.readUInt32LE(data.length - 4);
    const occurredAt = decodeZkTime(timeValue);

    if (!userId || Number.isNaN(occurredAt.getTime()) || occurredAt.getFullYear() < 2020) {
      logger.debug('zkteco: tanınmayan gerçek zamanlı paket', data.toString('hex'));
      return null;
    }

    return { userId, occurredAt };
  } catch (err) {
    logger.debug('zkteco: paket ayrıştırma hatası', err.message, data.toString('hex'));
    return null;
  }
}

function createZktecoDriver({ config, logger, onEvent }) {
  const cfg = config.zkteco || {};
  let socket = null;
  let sessionId = 0;
  let replyId = 0;
  let reconnectDelay = 2000;
  let reconnectTimer = null;
  let stopped = false;
  let recvBuffer = Buffer.alloc(0);

  function send(command, data) {
    replyId = (replyId + 1) & 0xffff;
    socket.write(buildPacket(command, sessionId, replyId, data));
  }

  function connect() {
    if (!cfg.ip) {
      logger.error('zkteco.ip config.json içinde tanımlı değil.');
      return;
    }

    socket = new net.Socket();
    socket.setTimeout(cfg.timeoutMs || 4000);
    recvBuffer = Buffer.alloc(0);

    socket.connect(cfg.port || 4370, cfg.ip, () => {
      logger.info(`ZKTeco cihazına bağlanıldı: ${cfg.ip}:${cfg.port || 4370}`);
      sessionId = 0;
      replyId = 0;
      send(CMD_CONNECT, Buffer.alloc(0));
    });

    socket.on('data', (chunk) => {
      recvBuffer = Buffer.concat([recvBuffer, chunk]);
      drainBuffer();
    });

    socket.on('timeout', () => {
      logger.warn('ZKTeco bağlantısı zaman aşımına uğradı, yeniden bağlanılacak');
      socket.destroy();
    });

    socket.on('error', (err) => logger.warn('ZKTeco soket hatası', err.message));

    socket.on('close', () => {
      if (stopped) return;
      logger.warn(`ZKTeco bağlantısı koptu, ${reconnectDelay / 1000}sn sonra yeniden denenecek`);
      reconnectTimer = setTimeout(connect, reconnectDelay);
      reconnectTimer.unref?.();
      reconnectDelay = Math.min(reconnectDelay * 2, 60000);
    });
  }

  function drainBuffer() {
    while (recvBuffer.length >= 8) {
      if (!recvBuffer.subarray(0, 4).equals(TCP_MAGIC)) {
        // Senkron kaybı: ilk baytı at, tekrar dene.
        recvBuffer = recvBuffer.subarray(1);
        continue;
      }
      const len = recvBuffer.readUInt32LE(4);
      if (recvBuffer.length < 8 + len) return; // paket henüz tam gelmedi

      const payload = recvBuffer.subarray(8, 8 + len);
      recvBuffer = recvBuffer.subarray(8 + len);
      handlePayload(payload);
    }
  }

  function handlePayload(payload) {
    if (payload.length < 8) return;
    const command = payload.readUInt16LE(0);
    sessionId = payload.readUInt16LE(4) || sessionId;
    const data = payload.subarray(8);

    if (command === CMD_ACK_OK && reconnectDelay !== 2000) {
      reconnectDelay = 2000; // başarılı bağlantı: geri çekilmeyi sıfırla
    }

    if (command === CMD_CONNECT || command === CMD_ACK_OK) {
      // CMD_CONNECT yanıtından hemen sonra gerçek zamanlı olay aboneliği başlat.
      const flags = Buffer.alloc(4);
      flags.writeUInt32LE(0xffff, 0);
      send(CMD_REG_EVENT, flags);
      return;
    }

    if (command === CMD_REG_EVENT && data.length > 0) {
      const parsed = parseRealTimeLog(data, logger);
      if (!parsed) return;

      onEvent({
        identifier: parsed.userId,
        identifier_kind: 'fingerprint',
        event_type: 'AUTO',
        occurred_at: parsed.occurredAt.toISOString(),
        idempotency_key: `zk-${cfg.ip}-${parsed.userId}-${parsed.occurredAt.getTime()}-${crypto.randomBytes(2).toString('hex')}`,
      });
    }
  }

  return {
    start() {
      stopped = false;
      connect();
    },
    stop() {
      stopped = true;
      if (reconnectTimer) clearTimeout(reconnectTimer);
      if (socket) {
        try {
          send(CMD_EXIT, Buffer.alloc(0));
        } catch {
          /* zaten kapanmış olabilir */
        }
        socket.destroy();
      }
    },
  };
}

module.exports = { createZktecoDriver, parseRealTimeLog, decodeZkTime };
