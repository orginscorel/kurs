<?php

namespace App\Services\Devices\Terminal;

use App\Services\Devices\Drivers\TerminalDriver;
use App\Services\Devices\Network\SocketFailure;
use Illuminate\Support\Facades\Log;

/**
 * İKİ AŞAMALI BAĞLANTI TESTİ
 *
 *   AŞAMA 1 — Ağ / soket: IP'ye yol var mı, TCP portu bağlantı kabul ediyor mu (süre, yerel kaynak IP, errno).
 *   AŞAMA 2 — Protokol: seçili sürücünün el sıkışması ve cihaz künyesi.
 *
 * Kural: soket açıldıysa sonuç ASLA "Cihaza bağlanılamadı" değildir; "Ağ bağlantısı başarılı fakat cihaz
 * protokolü doğrulanamadı" olur ve hangi aşamanın neden geçmediği ayrı ayrı yazılır.
 */
class TerminalConnectionTester
{
    public function __construct(private readonly TerminalStateStore $state) {}

    public function run(TerminalDriver $driver, TerminalEndpoint $endpoint): TerminalTestReport
    {
        $stages = [];
        $probe = null;

        // ---- AŞAMA 1
        if ($endpoint->transport === 'udp') {
            $stages['ag'] = TestStage::skip('Ağ yolu', 'UDP bağlantısızdır; ağ erişimi protokol aşamasında anlaşılır.');
            $stages['tcp'] = TestStage::skip("UDP {$endpoint->port}", 'UDP için soket testi yapılmaz.');
            $socketOk = true;
        } else {
            $probe = $driver->testNetwork($endpoint);
            $hostReached = $probe->connected || $probe->failure === SocketFailure::Refused;

            $stages['ag'] = $hostReached
                ? TestStage::pass('Ağ yolu', "{$endpoint->host} adresine ulaşıldı".($probe->localIp ? " (köprü IP'si {$probe->localIp})" : '').'.')
                : TestStage::fail('Ağ yolu', $probe->message, $probe->hint);
            $stages['tcp'] = $probe->connected
                ? TestStage::pass("TCP {$endpoint->port}", "Bağlantı açıldı · {$probe->durationMs} ms")
                : TestStage::fail("TCP {$endpoint->port}", $probe->message, $probe->hint);
            $socketOk = $probe->connected;
        }

        // ---- AŞAMA 2
        $identity = null;
        $protocol = null;

        if (! $socketOk) {
            $stages['protokol'] = TestStage::skip('Protokol el sıkışması', 'Soket açılmadığı için denenmedi.');
            $stages['kimlik'] = TestStage::skip('Cihaz tanıma', 'Soket açılmadığı için denenmedi.');
        } else {
            $protocol = $driver->identifyDevice($endpoint);
            $stages['protokol'] = match ($protocol->status) {
                DriverStatus::Ok => TestStage::pass('Protokol el sıkışması', $driver->label().' yanıt verdi.'),
                DriverStatus::ProtocolNotImplemented => TestStage::notVerified('Protokol el sıkışması', $protocol->message, $protocol->hint),
                DriverStatus::Unsupported => TestStage::notVerified('Protokol el sıkışması', $protocol->message, $protocol->hint),
                DriverStatus::AuthError => TestStage::fail('Protokol el sıkışması', $protocol->message, $protocol->hint),
                default => TestStage::fail('Protokol el sıkışması', $protocol->message, $protocol->hint),
            };

            if ($protocol->isOk()) {
                $identity = is_array($protocol->data) ? array_filter($protocol->data, fn ($v) => $v !== null && $v !== '') : [];
                $stages['kimlik'] = ! empty($identity['seri_no']) || ! empty($identity['cihaz_adi'])
                    ? TestStage::pass('Cihaz tanıma', trim(($identity['cihaz_adi'] ?? '').' '.($identity['seri_no'] ?? '')))
                    : TestStage::fail('Cihaz tanıma', 'Cihaz yanıt verdi ama künye (seri no/model) okunamadı.');
            } else {
                $stages['kimlik'] = TestStage::skip('Cihaz tanıma', 'Protokol doğrulanmadan cihaz tanınamaz.');
            }
        }

        $report = $this->summarize($driver, $endpoint, $stages, $probe?->toArray(), $protocol, $identity);

        $this->remember($driver, $endpoint, $report);
        Log::channel('terminal')->info('İki aşamalı test', ['surucu' => $driver->key(), 'hedef' => $endpoint->label(), 'durum' => $report->status, 'asamalar' => array_map(fn (TestStage $s) => $s->status, $stages)]);

        return $report;
    }

    private function summarize(TerminalDriver $driver, TerminalEndpoint $endpoint, array $stages, ?array $socket, ?DriverResult $protocol, ?array $identity): TerminalTestReport
    {
        $tcp = $stages['tcp'];
        $proto = $stages['protokol'];

        if ($tcp->status === TestStage::FAIL) {
            return new TerminalTestReport('hata', 'baglanti', 'Cihaza bağlanılamadı: '.$tcp->detail, $tcp->hint ?? '', $driver, $endpoint, $stages, $socket, $identity);
        }

        if ($proto->status === TestStage::PASS) {
            return new TerminalTestReport('ok', null, 'Cihaza bağlanıldı ve protokol doğrulandı.', '', $driver, $endpoint, $stages, $socket, $identity);
        }

        $code = match ($protocol?->status) {
            DriverStatus::ProtocolNotImplemented => 'protokol_dogrulanmadi',
            DriverStatus::Unsupported => 'desteklenmiyor',
            DriverStatus::AuthError => 'kimlik',
            default => 'protokol',
        };

        $prefix = $endpoint->transport === 'udp'
            ? 'Cihaz protokolü doğrulanamadı.'
            : 'Ağ bağlantısı başarılı fakat cihaz protokolü doğrulanamadı.';

        // Ayrıntı (sürücüye özgü neden) aşama tablosunda; özet tek cümle + seçili sürücü — metin iki kez yazılmaz.
        return new TerminalTestReport('kismi', $code, $prefix.' Seçili sürücü: '.$driver->label().'.', $proto->hint ?? '', $driver, $endpoint, $stages, $socket, $identity);
    }

    private function remember(TerminalDriver $driver, TerminalEndpoint $endpoint, TerminalTestReport $report): void
    {
        if ($endpoint->deviceId === null) {
            return;
        }

        $this->state->putDevice($endpoint->deviceId, [
            'surucu' => $driver->key(),
            'hedef' => $endpoint->label(),
            'kopru_ip' => $report->socket['yerel_ip'] ?? null,
            'tcp_durum' => $report->stages['tcp']->status,
            'tcp_sure_ms' => $report->socket['sure_ms'] ?? null,
            'protokol_durum' => $report->stages['protokol']->status,
            'son_test' => now()->toIso8601String(),
            'son_test_durum' => $report->status,
            'son_hata' => $report->status === 'ok' ? null : mb_substr($report->message, 0, 300),
            'macos_yerel_ag_izni_olasi' => (bool) ($report->socket['macos_yerel_ag_izni_olasi'] ?? false),
        ]);
    }
}
