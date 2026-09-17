#!/usr/bin/env node
'use strict';

/**
 * Windows servis kurulum/kaldırma betiği (node-windows).
 * Kullanım:
 *   npm run install-windows-service
 *   npm run uninstall-windows-service
 *
 * node-windows opsiyonel bağımlılıktır (yalnız Windows'ta gerekir). Linux/systemd
 * dağıtımı için bkz. ../systemd/kurs-gateway.service.
 */

const path = require('path');

function loadNodeWindows() {
  try {
    return require('node-windows');
  } catch {
    console.error('node-windows kurulu değil. Windows üzerinde: cd gateway && npm install node-windows');
    process.exit(1);
  }
}

function main() {
  const action = process.argv[2];
  if (!['install', 'uninstall'].includes(action)) {
    console.error('Kullanım: node src/windows-service.js install|uninstall');
    process.exit(1);
  }

  const { Service } = loadNodeWindows();

  const svc = new Service({
    name: 'Erbaa Bilgi Yoklama Köprüsü',
    description: 'Kurs yönetim sistemi — yerel donanım köprüsü (parmak izi/RFID/QR terminalleri).',
    script: path.join(__dirname, 'index.js'),
    nodeOptions: [],
  });

  svc.on('install', () => {
    console.log('Servis kuruldu, başlatılıyor…');
    svc.start();
  });
  svc.on('alreadyinstalled', () => console.log('Servis zaten kurulu.'));
  svc.on('uninstall', () => console.log('Servis kaldırıldı.'));
  svc.on('error', (err) => console.error('Servis hatası:', err));

  if (action === 'install') svc.install();
  else svc.uninstall();
}

main();
