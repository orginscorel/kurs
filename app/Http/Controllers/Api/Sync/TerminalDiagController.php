<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Api\ApiController;
use App\Services\Devices\Analysis\HarSanitizer;
use App\Support\BranchContext;
use App\Sync\Local\SyncClient;
use App\Sync\Models\SyncDevice;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UZAKTAN TANI PAKETİ — masaüstü köprü, cihaz jetonuyla terminal teşhis verisini yükler (upload); web yöneticisi
 * (devices.manage) listeler ve indirir. Blob storage/app/private/terminal-diag altında; kayıtta özet + yol.
 */
class TerminalDiagController extends ApiController
{
    /** Cihaz jetonuyla yükleme (routes/api/sync.php, sync.device ara katmanı). */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'blob' => ['required', 'file', 'max:51200'],   // ≤50 MB (sıkıştırılmış)
            'summary' => ['nullable', 'string', 'max:20000'],
            'device_label' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40'],
        ], ['blob.required' => 'Tanı paketi dosyası gerekli.']);

        /** @var SyncDevice $device */
        $device = $request->attributes->get('sync_device');
        $summary = json_decode((string) $request->input('summary', '{}'), true) ?: [];

        $path = 'terminal-diag/'.($device?->branch_id ?? 0).'/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.bin';
        Storage::disk('local')->putFileAs('private/'.dirname($path), $request->file('blob'), basename($path));
        $size = (int) Storage::disk('local')->size('private/'.$path);

        // En yeni 40 paket kalsın (şube başına)
        $old = DB::table('terminal_diag_bundles')->where('branch_id', $device?->branch_id)->orderByDesc('id')->skip(40)->take(100)->pluck('blob_path', 'id');
        foreach ($old as $id => $p) {
            if ($p) {
                Storage::disk('local')->delete('private/'.$p);
            }
            DB::table('terminal_diag_bundles')->where('id', $id)->delete();
        }

        $id = (int) DB::table('terminal_diag_bundles')->insertGetId([
            'branch_id' => $device?->branch_id,
            'sync_device_id' => $device?->id,
            'device_label' => $request->input('device_label'),
            'app_version' => $request->input('app_version'),
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE),
            'blob_path' => $path,
            'blob_size' => $size,
            'created_at' => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Tanı paketi alındı.']);
    }

    /** Web yöneticisi: liste (devices.manage). */
    /**
     * CİHAZ WEB PANELİ KAYDI (HAR) — yönetici tarayıcıdan gönderir (2026-09-21). Perkotek panelinin cihaza
     * gönderdiği istekleri ve JS dosyalarını geliştiriciye ulaştırır (cihaz yerel ağda, sunucudan erişilemez).
     * Çerez / oturum başlıkları ve parola alanları KAYDA YAZILMADAN silinir (HarSanitizer). Masaüstünden
     * (yerel düğüm) gönderilirse eşitleme bağlantısıyla sunucuya iletilir.
     */
    public function uploadHar(Request $request, HarSanitizer $temizleyici): JsonResponse
    {
        $request->validate([
            'dosya' => ['required', 'file', 'max:61440'],   // ≤60 MB ham HAR
            'not' => ['nullable', 'string', 'max:500'],
        ], ['dosya.required' => 'HAR dosyası seçin.', 'dosya.max' => 'Dosya en çok 60 MB olabilir.']);

        $t = $temizleyici->temizle((string) file_get_contents($request->file('dosya')->getRealPath()));
        $ozet = ['tur' => 'har', 'istek' => $t['istek'], 'hostlar' => $t['hostlar'], 'not' => $request->input('not'),
            'gonderen' => $request->user()?->name, 'dosya_adi' => $request->file('dosya')->getClientOriginalName()];
        $gz = gzencode($t['json'], 6);

        if (config('kurs.node') === 'local') {
            $gecici = tempnam(sys_get_temp_dir(), 'har');
            file_put_contents($gecici, $gz);
            try {
                app(SyncClient::class)->uploadDiag($gecici, $ozet, 'Web paneli kaydı', (string) config('kurs.version', ''));
            } finally {
                @unlink($gecici);
            }
        } else {
            $path = 'terminal-diag/'.(app(BranchContext::class)->id() ?? 0).'/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.har.gz';
            Storage::disk('local')->put('private/'.$path, $gz);
            DB::table('terminal_diag_bundles')->insert([
                'branch_id' => app(BranchContext::class)->id(), 'sync_device_id' => null, 'device_label' => 'Web paneli kaydı',
                'app_version' => null, 'summary' => json_encode($ozet, JSON_UNESCAPED_UNICODE), 'blob_path' => $path,
                'blob_size' => strlen($gz), 'created_at' => now(),
            ]);
        }

        Audit::log('terminal.panel_har_gonderildi', 'Cihaz web paneli kaydı gönderdi ('.$t['istek'].' istek).');

        return response()->json(['message' => 'Kayıt gönderildi ('.$t['istek'].' istek). Parola ve oturum bilgileri silindi.', 'istek' => $t['istek'], 'hostlar' => $t['hostlar']]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('terminal_diag_bundles')->orderByDesc('id')->limit(100)
            ->get(['id', 'device_label', 'app_version', 'summary', 'blob_size', 'created_at']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id,
            'cihaz' => $r->device_label,
            'surum' => $r->app_version,
            'boyut' => (int) $r->blob_size,
            'zaman' => $r->created_at,
            'ozet' => json_decode((string) $r->summary, true) ?: null,
        ])->all()]);
    }

    public function download(int $id): StreamedResponse
    {
        $row = DB::table('terminal_diag_bundles')->find($id);
        abort_if(! $row || ! $row->blob_path || ! Storage::disk('local')->exists('private/'.$row->blob_path), 404, 'Tanı paketi bulunamadı.');
        Audit::log('terminal.diag_downloaded', "Terminal tanı paketi #{$id} indirildi.");

        return Storage::disk('local')->download('private/'.$row->blob_path, "terminal-tani-{$id}.tar.gz");
    }
}
