<?php

namespace App\Sync\Contracts;

/**
 * Terminal durum raporunun GENİŞLETME NOKTASI (yerel düğüm). TerminalStatusReporter her terminal için temel alanları
 * (last_seen_at, last_pull_at, status, error, record_count) devices satırından okur; terminal katmanı ek teşhis
 * alanlarını bu arayüzle verir. Dönen dizi raporda `details` olarak sunucuya gider, `sync_terminal_reports.details`
 * sütununda saklanır ve web'deki `bridge.details` bloğunda aynen döner.
 *
 * Beklenen (isteğe bağlı) anahtarlar: driver, mode, tcp_status, protocol_status, push_listener {active, port},
 * last_packet_at (ISO 8601), pending_queue (int), last_error_code. Yalnız skaler/iç içe skaler değerler; en çok
 * 40 anahtar, JSON hâli en çok 4 KB (fazlası sunucuda atılır). IP adresi, iletişim şifresi, jeton, ham paket
 * GÖNDERİLMEMELİ (web'de görünür).
 */
interface TerminalStatusProvider
{
    /**
     * @param object $device devices satırı (stdClass: id, uuid, protocol, zk_* …)
     * @return array<string, mixed>
     */
    public function details(object $device): array;
}
