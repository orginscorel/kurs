<?php

namespace App\Http\Controllers\Devices;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Devices\Adms\AdmsService;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ADMS / iclock uçları — TERMİNALİN KENDİSİ çağırır (oturum yok, çerez yok, JSON yok).
 *
 * Güvenlik sınırları (bilinçli):
 *   - Uçlar yalnız `devices_adms.enabled` açıkken vardır (varsayılan: yalnız yerel düğüm).
 *   - Kimlik = cihazın seri numarası VE seri no önceden kayıtlı olmalı (protocol = 'adms').
 *     Tanınmayan seri no veri YAZAMAZ; yalnız "tanıtıldı ama ekli değil" listesine düşer,
 *     yönetici ekrandan onaylayıp ekler.
 *   - Yanıtlar cihazın beklediği düz metindir; hata durumunda bile gövde "OK" benzeri kalır,
 *     çünkü cihaz JSON/HTML anlamaz ve anlamadığında kayıtları tekrar tekrar gönderir.
 *
 * Şube bağlamı: istek oturumsuz olduğundan cihazın kendi `branch_id`'si kullanılır.
 */
class AdmsController extends Controller
{
    public function __construct(private readonly AdmsService $adms) {}

    /** GET /iclock/cdata — el sıkışma: cihaz ayarlarını ister. */
    public function handshake(Request $request): Response
    {
        $device = $this->resolve($request);

        if (! $device) {
            return $this->text("OK\n");
        }

        return $this->text($this->run($device, fn () => $this->adms->handshake($device)));
    }

    /** POST /iclock/cdata — cihaz kayıt gönderiyor (table=ATTLOG | OPERLOG | USERINFO). */
    public function push(Request $request): Response
    {
        $device = $this->resolve($request);

        if (! $device) {
            // Cihaz "OK" görmezse aynı satırları sonsuza kadar tekrar gönderir; kaydı yok diye
            // döngüye sokmuyoruz. Yönetici cihazı ekleyince kayıtlar zaten tekrar gelir.
            return $this->text("OK\n");
        }

        $table = mb_strtoupper((string) $request->query('table', 'ATTLOG'));
        $body = (string) $request->getContent();

        return $this->text($this->run($device, function () use ($device, $table, $body, $request) {
            $this->adms->rememberStamp($device, $request->query('Stamp') ? (string) $request->query('Stamp') : null);

            if ($table !== 'ATTLOG') {
                // OPERLOG / USERINFO / ATTPHOTO: şimdilik yalnız nabız sayılır, veri işlenmez.
                $this->adms->touch($device);

                return "OK\n";
            }

            $stats = $this->adms->ingestAttlog($device, $body);

            return 'OK: '.$stats['okunan']."\n";
        }));
    }

    /** GET /iclock/getrequest — cihaz "bana komut var mı?" diye sorar. Nabız olarak kullanılır. */
    public function poll(Request $request): Response
    {
        $device = $this->resolve($request);

        if ($device) {
            $this->run($device, function () use ($device) {
                $this->adms->touch($device);

                return null;
            });
        }

        // Kuyruğa alınmış komut yok. (Cihaza komut göndermek ayrı bir iştir; şimdilik yazılmadı.)
        return $this->text("OK\n");
    }

    /** POST /iclock/devicecmd — cihaz komut sonucunu bildirir. */
    public function commandResult(Request $request): Response
    {
        $device = $this->resolve($request);

        if ($device) {
            $this->run($device, function () use ($device) {
                $this->adms->touch($device);

                return null;
            });
        }

        return $this->text("OK\n");
    }

    private function resolve(Request $request): ?Device
    {
        if (! config('devices_adms.enabled')) {
            return null;
        }

        $serial = (string) ($request->query('SN') ?? $request->query('sn') ?? '');

        return $serial === '' ? null : $this->adms->device($serial);
    }

    /** Cihazın şubesi bağlam olarak kurulur; hata hiçbir zaman cihaza sızmaz. */
    private function run(Device $device, callable $work): string
    {
        try {
            return (string) (app(BranchContext::class)->run($device->branch_id, $work) ?? "OK\n");
        } catch (\Throwable $e) {
            report($e);

            return "OK\n";
        }
    }

    private function text(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
