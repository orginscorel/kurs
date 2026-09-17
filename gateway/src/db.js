'use strict';

const fs = require('fs');
const path = require('path');
const { DatabaseSync } = require('node:sqlite');

/*
 * Yerel SQLite kuyruğu. Node'un yerleşik `node:sqlite` modülünü kullanır (Node
 * 22.5+, deneysel ama stabil) — böylece better-sqlite3 gibi native derleme
 * gerektiren bir bağımlılık olmadan, Windows/Linux fark etmeksizin kurulum
 * "npm install" ile derleyici/araç kurulmadan çalışır (saha teknisyeni için
 * önemli: build tool zinciri kurulu olmayan bir Windows bilgisayarda bile çalışır).
 *
 * İnternet kesilse de olay kaybolmaz: cihazdan okunan her olay önce buraya
 * yazılır, arka plandaki gönderici (sender.js) başarıyla teslim edince (ya da
 * sunucu "duplicate" deyince) satırı siler.
 */
function openQueue(dbPath, logger) {
  fs.mkdirSync(path.dirname(dbPath), { recursive: true });
  const db = new DatabaseSync(dbPath);
  db.exec('PRAGMA journal_mode = WAL');

  db.exec(`
    CREATE TABLE IF NOT EXISTS event_queue (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      identifier TEXT,
      identifier_kind TEXT,
      student_id INTEGER,
      event_type TEXT NOT NULL,
      occurred_at TEXT NOT NULL,
      idempotency_key TEXT NOT NULL UNIQUE,
      created_at TEXT NOT NULL,
      attempts INTEGER NOT NULL DEFAULT 0,
      last_error TEXT
    );

    CREATE TABLE IF NOT EXISTS identities_cache (
      kind TEXT NOT NULL,
      identifier TEXT NOT NULL,
      student_id INTEGER,
      full_name TEXT,
      student_no TEXT,
      synced_at TEXT,
      PRIMARY KEY (kind, identifier)
    );
  `);

  const insertStmt = db.prepare(`
    INSERT OR IGNORE INTO event_queue
      (identifier, identifier_kind, student_id, event_type, occurred_at, idempotency_key, created_at)
    VALUES (@identifier, @identifier_kind, @student_id, @event_type, @occurred_at, @idempotency_key, @created_at)
  `);

  // attempts >= 10 olan kayıtlar (bozuk/kabul edilmeyen olay) taze olayları bloklamasın diye
  // normal toplu gönderimden hariç tutulur; kuyrukta kalır, "queue size" uyarısında görünür.
  const batchStmt = db.prepare(`SELECT * FROM event_queue WHERE attempts < 10 ORDER BY occurred_at ASC LIMIT ?`);
  const poisonedCountStmt = db.prepare(`SELECT COUNT(*) AS c FROM event_queue WHERE attempts >= 10`);
  const deleteStmt = db.prepare(`DELETE FROM event_queue WHERE idempotency_key = ?`);
  const countStmt = db.prepare(`SELECT COUNT(*) AS c FROM event_queue`);
  const bumpAttemptStmt = db.prepare(`UPDATE event_queue SET attempts = attempts + 1, last_error = ? WHERE idempotency_key = ?`);

  const upsertIdentityStmt = db.prepare(`
    INSERT INTO identities_cache (kind, identifier, student_id, full_name, student_no, synced_at)
    VALUES (@kind, @identifier, @student_id, @full_name, @student_no, @synced_at)
    ON CONFLICT(kind, identifier) DO UPDATE SET student_id=excluded.student_id, full_name=excluded.full_name, student_no=excluded.student_no, synced_at=excluded.synced_at
  `);
  const lookupIdentityStmt = db.prepare(`SELECT * FROM identities_cache WHERE kind = ? AND identifier = ?`);
  const clearIdentitiesStmt = db.prepare(`DELETE FROM identities_cache`);

  function withTransaction(fn) {
    db.exec('BEGIN IMMEDIATE');
    try {
      fn();
      db.exec('COMMIT');
    } catch (err) {
      db.exec('ROLLBACK');
      throw err;
    }
  }

  return {
    raw: db,

    /** Yeni okutmayı kuyruğa ekler; idempotency_key çakışırsa sessizce yok sayılır (aynı olay iki kez okutulmuşsa). */
    enqueue(event) {
      const row = {
        identifier: event.identifier ?? null,
        identifier_kind: event.identifier_kind ?? null,
        student_id: event.student_id ?? null,
        event_type: event.event_type,
        occurred_at: event.occurred_at,
        idempotency_key: event.idempotency_key,
        created_at: new Date().toISOString(),
      };
      const info = insertStmt.run(row);
      if (info.changes > 0) logger?.debug('kuyruğa eklendi', row.idempotency_key);
      return info.changes > 0;
    },

    nextBatch(limit) {
      return batchStmt.all(limit);
    },

    /** Sunucu işledi (accepted/duplicate/unmatched/debounced) — yerelde tutmaya gerek yok. */
    removeProcessed(idempotencyKeys) {
      withTransaction(() => idempotencyKeys.forEach((k) => deleteStmt.run(k)));
    },

    markAttemptFailed(idempotencyKey, error) {
      bumpAttemptStmt.run(String(error).slice(0, 500), idempotencyKey);
    },

    queueSize() {
      return countStmt.get().c;
    },

    poisonedCount() {
      return poisonedCountStmt.get().c;
    },

    upsertIdentities(identities) {
      withTransaction(() => {
        clearIdentitiesStmt.run();
        const now = new Date().toISOString();
        identities.forEach((r) =>
          upsertIdentityStmt.run({
            kind: r.kind,
            identifier: r.identifier,
            student_id: r.student_id ?? null,
            full_name: r.full_name ?? null,
            student_no: r.student_no ?? null,
            synced_at: now,
          }),
        );
      });
    },

    /** Yerel eşleşme (opsiyonel): sürücüler kiosk geri bildirimi için kullanabilir; asıl yetkilendirme sunucuda yapılır. */
    lookupIdentity(kind, identifier) {
      return lookupIdentityStmt.get(kind, identifier) ?? null;
    },

    close() {
      db.close();
    },
  };
}

module.exports = { openQueue };
