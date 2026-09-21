<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Device;
use App\Services\Devices\Terminal\Data\DeviceUser;
use App\Services\Devices\WebPanel\WebPanelDriver;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Perkotek YT33 "Dynamic Face" web paneli (HTTP /bin/cmd) — cihaza yazma kanalı ayarı + işlemler.
 * Ayar okuma web'de açık; cihaza dokunan her uç (kaydet/test/liste/sil) yalnız yerel düğümde (terminal.desktop).
 * Panel şifresi hiçbir yanıtta dönmez (yalnız "girilmiş mi" bilgisi).
 */
class WebPanelController extends ApiController
{
    public function __construct(private readonly WebPanelDriver $panel) {}

    /** Cihazın web paneli ayarı (sırsız). */
    public function show(Device $device): JsonResponse
    {
        return response()->json(['data' => $this->describe($device)]);
    }

    /** Panel kimlik bilgilerini kaydet. Şifre gönderilmezse korunur; boş gönderilirse kaldırılır. */
    public function save(Request $request, Device $device): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['nullable', 'string', 'max:64'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'kullanici' => ['required', 'string', 'max:64'],
            'sifre' => ['nullable', 'string', 'max:128'],
        ], [
            'kullanici.required' => 'Panel kullanıcı adı gerekli.',
        ], [
            'ip' => 'Cihaz IP', 'port' => 'Panel portu', 'kullanici' => 'Panel kullanıcı adı', 'sifre' => 'Panel şifresi',
        ]);

        if (! empty($data['ip'])) {
            $device->zk_ip = trim($data['ip']);
        }
        $device->panel_user = trim($data['kullanici']);
        $device->panel_port = (int) ($data['port'] ?? $device->panel_port ?: 80);
        // Şifre alanı istekte varsa güncelle (boş = kaldır); yoksa mevcut şifre korunur. Günlüğe asla yazılmaz.
        if ($request->exists('sifre')) {
            $device->panel_password = $data['sifre'] ?? null;
        }
        $device->save();

        Audit::log('device.webpanel_settings_saved', "\"{$device->name}\" cihazının web paneli ayarını güncelledi (#{$device->id}).", $device);

        return response()->json(['message' => 'Web paneli ayarı kaydedildi.', 'data' => $this->describe($device->fresh())]);
    }

    /** Bağlantı + kimlik testi (GetDeviceInfo). */
    public function test(Device $device): JsonResponse
    {
        $r = $this->panel->deviceInfo($device);
        if (! $r->isOk()) {
            return response()->json($r->toArray(), 422);
        }

        $rd = is_array($r->data) ? $r->data : [];

        return response()->json(['durum' => 'ok', 'mesaj' => 'Cihaza bağlanıldı.', 'cihaz' => [
            'ad' => $rd['name'] ?? null,
            'seri' => $rd['deviceId'] ?? null,
            'firmware' => $rd['firmware'] ?? null,
            'kullanici_sayisi' => $rd['userCount'] ?? null,
            'kullanici_limit' => $rd['userLimit'] ?? null,
            'yuz_sayisi' => $rd['faceCount'] ?? null,
            'parmak_sayisi' => $rd['fpCount'] ?? null,
        ]]);
    }

    /** Cihazdaki kullanıcı listesi (biyometrik içerik gelmez, yalnız sayılar). */
    public function users(Device $device): JsonResponse
    {
        $r = $this->panel->fetchUsers($device);
        if (! $r->isOk()) {
            return response()->json($r->toArray(), 422);
        }

        return response()->json(['data' => array_map(fn (DeviceUser $u) => $u->toArray(), $r->data ?? [])]);
    }

    /** Cihazdan TEK kullanıcıyı sil (onaylı düğme). Yalnız verilen numara silinir. */
    public function deleteUser(Device $device, string $no): JsonResponse
    {
        $r = $this->panel->deleteUsers($device, [$no]);
        if (! $r->isOk()) {
            return response()->json($r->toArray(), 422);
        }

        DB::table('terminal_device_users')->where('device_id', $device->id)->where('user_no', $no)->delete();
        Audit::log('device.webpanel_user_deleted', "Cihaz #{$device->id} üzerinden {$no} numaralı kullanıcı silindi.", $device);

        return response()->json(['message' => "Cihazdan {$no} numaralı kullanıcı silindi. Kişinin PDKS eşlemesini kaldırmak isterseniz Kişiler listesinden yapabilirsiniz."]);
    }

    private function describe(Device $device): array
    {
        return [
            'id' => $device->id,
            'ad' => $device->name,
            'ip' => $device->zk_ip,
            'port' => (int) ($device->panel_port ?: 80),
            'kullanici' => $device->panel_user,
            'sifre_var' => ! empty($device->panel_password_encrypted),
            'hazir' => $device->supportsWebPanel(),
        ];
    }
}
