<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Device;
use App\Services\Attendance\PdksService;
use App\Services\Attendance\TerminalEnrollmentService;
use App\Services\Devices\Terminal\TerminalStateStore;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDKS (kurumun kendi personel/öğrenci devam kontrol sistemi) — Terminal kişileri, eşleştirme, giriş-çıkış
 * kayıtları, günlük/aylık özet, terminal sağlığı. Yazma uçları yalnız masaüstünde (terminal.desktop).
 *
 * Cihaza DOKUNAN işlemler (cihaza kullanıcı ekle/sil, parmak/yüz kaydı, cihazdan indir, saat eşitle) burada YOK:
 * YT33 protokolü doğrulanana kadar ekranda pasif düğme + cihaz menüsü yönergesi olarak görünür.
 */
class PdksController extends ApiController
{
    public function __construct(private readonly PdksService $pdks) {}

    private function branch(): int
    {
        return app(BranchContext::class)->require();
    }

    public function people(Request $request): JsonResponse
    {
        return response()->json($this->pdks->people($this->branch(), $request->query('q')));
    }

    public function search(Request $request): JsonResponse
    {
        $type = $request->query('tur');

        return response()->json(['data' => $this->pdks->searchPeople($this->branch(), (string) $request->query('q', ''), in_array($type, ['student', 'teacher', 'employee'], true) ? $type : null)]);
    }

    // ------------------------------------------------------------ terminale kayıt sihirbazı

    public function enrollStart(Request $request, TerminalEnrollmentService $enroll): JsonResponse
    {
        $data = $request->validate([
            'kisi_turu' => ['required', Rule::in(array_keys(PdksService::PERSON_TYPES))],
            'kisi_id' => ['required', 'integer', 'min:1'],
        ], ['kisi_id.required' => 'Kişi seçilmelidir.'], ['kisi_turu' => 'Kişi türü', 'kisi_id' => 'Kişi']);

        return response()->json(['data' => $enroll->start($this->branch(), $data['kisi_turu'], (int) $data['kisi_id'])], 201);
    }

    public function enrollStatus(int $session, TerminalEnrollmentService $enroll): JsonResponse
    {
        return response()->json(['data' => $enroll->status($this->branch(), $session)]);
    }

    public function enrollExtend(int $session, TerminalEnrollmentService $enroll): JsonResponse
    {
        return response()->json(['data' => $enroll->extend($this->branch(), $session)]);
    }

    public function enrollCancel(int $session, TerminalEnrollmentService $enroll): JsonResponse
    {
        $enroll->cancel($this->branch(), $session);

        return response()->json(['message' => 'Kayıt iptal edildi.']);
    }

    public function unenrolled(Request $request, TerminalEnrollmentService $enroll): JsonResponse
    {
        $type = $request->query('tur');

        return response()->json($enroll->unenrolled($this->branch(), in_array($type, ['student', 'teacher', 'employee'], true) ? $type : null, $request->query('q')));
    }

    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kullanici_no' => ['required', 'string', 'max:120', 'regex:/^[0-9A-Za-z_.-]+$/'],
            'kisi_turu' => ['required', Rule::in(array_keys(PdksService::PERSON_TYPES))],
            'kisi_id' => ['required', 'integer'],
            'tur' => ['nullable', Rule::in(['fingerprint', 'card'])],
            'onay' => ['nullable', 'boolean'],
        ], [
            'kullanici_no.required' => 'Cihazdaki kullanıcı numarası zorunludur.',
            'kullanici_no.regex' => 'Kullanıcı numarası yalnız harf, rakam, nokta, tire ve alt çizgi içerebilir.',
            'kisi_id.required' => 'Kişi seçilmelidir.',
        ], ['kullanici_no' => 'Cihaz kullanıcı no', 'kisi_turu' => 'Kişi türü', 'kisi_id' => 'Kişi']);

        $result = $this->pdks->link($this->branch(), $data['kullanici_no'], $data['kisi_turu'], (int) $data['kisi_id'], (bool) ($data['onay'] ?? false), $data['tur'] ?? 'fingerprint');

        return $this->ok("{$result['kisi']} eşlendi".($result['baglanan_eski_okutma'] ? " · {$result['baglanan_eski_okutma']} bekleyen okutma bağlandı" : '').'.', $result);
    }

    public function csvPreview(Request $request): JsonResponse
    {
        $data = $request->validate(['icerik' => ['required', 'string', 'max:500000']], ['icerik.required' => 'CSV içeriği boş.']);

        return response()->json(['data' => $this->pdks->previewCsv($this->branch(), $data['icerik'])]);
    }

    public function csvApply(Request $request): JsonResponse
    {
        $data = $request->validate(['icerik' => ['required', 'string', 'max:500000'], 'uzerine_yaz' => ['nullable', 'boolean']]);
        $result = $this->pdks->applyCsv($this->branch(), $data['icerik'], (bool) ($data['uzerine_yaz'] ?? false));
        Audit::log('device_identity.csv_imported', "PDKS CSV eşleştirmesi: {$result['eslenen']} eşlendi, {$result['atlanan']} atlandı.");

        return $this->ok("{$result['eslenen']} eşleme kaydedildi, {$result['atlanan']} satır atlandı.", $result);
    }

    public function events(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $page = $this->pdks->eventsQuery($this->branch(), $filters)->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => $this->pdks->decorate(collect($page->items()), $this->branch()),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->pdks->dailySummary($this->branch(), $this->filters($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $branch = $this->branch();
        $query = $this->pdks->eventsQuery($branch, $filters);
        Audit::log('attendance.pdks_exported', 'PDKS giriş-çıkış kayıtlarını Excel olarak dışa aktardı.');

        return response()->streamDownload(function () use ($query, $branch) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['Zaman', 'Yön', 'Kişi türü', 'Kişi', 'Öğrenci no', 'Cihaz kullanıcı no', 'Doğrulama', 'Cihaz', 'Eşleşti']));
            $query->chunk(1000, function ($chunk) use ($writer, $branch) {
                foreach ($this->pdks->decorate(collect($chunk), $branch) as $r) {
                    $writer->addRow(Row::fromValues([
                        (string) $r['zaman'], $r['yon'] === 'ENTRY' ? 'Giriş' : ($r['yon'] === 'EXIT' ? 'Çıkış' : 'Bilinmiyor'),
                        $r['kisi_turu_etiketi'] ?? '', $r['kisi'] ?? '', $r['kisi_no'] ?? '', $r['kullanici_no'] ?? '', $r['yontem'], $r['cihaz'] ?? '', $r['eslesti'] ? 'Evet' : 'Hayır',
                    ]));
                }
            });
            $writer->close();
        }, 'pdks-giris-cikis-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Terminal sağlığı: son TCP testi / son push paketi / son okutma / bekleyen eşleşme / son eşitleme. */
    public function health(TerminalStateStore $state): JsonResponse
    {
        $branch = $this->branch();
        $devices = Device::query()->withoutGlobalScope('branch')->where('branch_id', $branch)
            ->whereIn('kind', ['fingerprint', 'face', 'rfid'])->orderBy('name')->get();
        $local = config('kurs.node') === 'local';

        $rows = $devices->map(function (Device $d) use ($state, $local, $branch) {
            $s = $local ? $state->device($d->id) : [];
            $lastPacket = $local && $d->zk_ip && \Illuminate\Support\Facades\Schema::hasTable('terminal_raw_packets')
                ? DB::table('terminal_raw_packets')->where('remote_ip', $d->zk_ip)->max('received_at') : null;
            $lastEvent = DB::table('attendance_events')->where('branch_id', $branch)->where('device_id', $d->id)->max('occurred_at');
            $pending = DB::table('attendance_events')->where('branch_id', $branch)->where('device_id', $d->id)->where('is_matched', false)->count();
            $signals = array_filter([$s['son_test'] ?? null, $lastPacket, $d->zk_last_pull_at?->toIso8601String()]);
            $lastSignal = $signals ? max(array_map(fn ($v) => strtotime((string) $v), $signals)) : null;
            $online = ($s['tcp_durum'] ?? null) === 'basarili' && $lastSignal && $lastSignal > time() - 900
                || ($lastPacket && strtotime((string) $lastPacket) > time() - 900);

            return [
                'id' => $d->id,
                'ad' => $d->name,
                'ip' => $d->zk_ip,
                'surucu' => $d->protocol,
                'cevrimici' => $local ? (bool) $online : null,
                'son_tcp_testi' => $s['son_test'] ?? null,
                'tcp' => $s['tcp_durum'] ?? null,
                'son_push_paketi' => $lastPacket,
                'son_okutma' => $lastEvent,
                'bekleyen_eslesme' => $pending,
            ];
        })->values();

        $sync = null;
        if ($local) {
            try {
                $sync = ['bekleyen' => app(\App\Sync\Local\LocalState::class)->pendingCount()];
            } catch (\Throwable) {
                $sync = null;
            }
        }

        return response()->json(['cihazlar' => $rows, 'esitleme' => $sync, 'yerel' => $local]);
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'baslangic' => ['nullable', 'date'],
            'bitis' => ['nullable', 'date'],
            'kisi_turu' => ['nullable', Rule::in(['hepsi', 'ogrenci', 'personel', 'eslesmeyen'])],
            'yon' => ['nullable', Rule::in(['ENTRY', 'EXIT'])],
            'kullanici_no' => ['nullable', 'string', 'max:120'],
            'kisi_tipi' => ['nullable', Rule::in(array_keys(PdksService::PERSON_TYPES))],
            'kisi_id' => ['nullable', 'integer'],
            'cihaz_id' => ['nullable', 'integer'],
            'mesai_baslangic' => ['nullable', 'date_format:H:i'],
            'tolerans_dk' => ['nullable', 'integer', 'between:0,120'],
        ], ['mesai_baslangic.date_format' => 'Mesai başlangıcı SS:DD biçiminde olmalıdır (ör. 09:00).']);
    }
}
