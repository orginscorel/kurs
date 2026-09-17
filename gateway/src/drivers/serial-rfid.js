'use strict';

const crypto = require('crypto');

/**
 * Seri port üzerinden kart okuyan RFID terminalleri. Çoğu ucuz RFID okuyucu her
 * kart okutmasında satır sonu ile biten bir metin (kart UID) gönderir.
 *
 * `serialport` paketi bu köprünün ana bağımlılıklarında YOKTUR (çoğu kurulumda
 * gerekmez, native derleme ister). RFID okuyucu kullanılacaksa:
 *   cd gateway && npm install serialport
 * paketini ayrıca kurun; bu sürücü yalnız o zaman etkinleşir.
 */
function createSerialRfidDriver({ config, logger, onEvent }) {
  let port = null;
  const cfg = config.serialRfid || {};

  return {
    start() {
      let SerialPort;
      let ReadlineParser;
      try {
        ({ SerialPort } = require('serialport'));
        ({ ReadlineParser } = require('@serialport/parser-readline'));
      } catch {
        logger.error(
          'serialport paketi kurulu değil. RFID sürücüsünü kullanmak için: cd gateway && npm install serialport @serialport/parser-readline',
        );
        return;
      }

      if (!cfg.path) {
        logger.error('serialRfid.path (COM portu / /dev/ttyUSBx) config.json içinde tanımlı değil.');
        return;
      }

      port = new SerialPort({ path: cfg.path, baudRate: cfg.baudRate || 9600 });
      const parser = port.pipe(new ReadlineParser({ delimiter: cfg.lineTerminator || '\n' }));

      port.on('open', () => logger.info(`RFID seri port açıldı: ${cfg.path}`));
      port.on('error', (err) => logger.error('RFID seri port hatası', err.message));

      parser.on('data', (line) => {
        const uid = String(line).trim().replace(/[^A-Za-z0-9]/g, '');
        if (!uid) return;
        logger.debug('RFID okutma', uid);
        onEvent({
          identifier: uid,
          identifier_kind: 'card',
          event_type: 'AUTO',
          occurred_at: new Date().toISOString(),
          idempotency_key: `rfid-${uid}-${Date.now()}-${crypto.randomBytes(3).toString('hex')}`,
        });
      });
    },
    stop() {
      if (port?.isOpen) port.close();
    },
  };
}

module.exports = { createSerialRfidDriver };
