<?php

namespace App\Services\Attendance;

use App\Exceptions\BusinessRuleException;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Services\Devices\Zk\ZkConnectionSettings;
use App\Services\Devices\Zk\ZkTerminal;
use App\Services\Devices\Zk\ZkUser;
use App\Support\Audit;
use App\Support\BranchContext;

/**
 * Terminal köprüsünün uygulama tarafı: bağlantı ayarları, bağlantı testi, cihaz kullanıcılarının
 * listelenmesi ve ÖĞRENCİ EŞLEŞTİRME ÖNERİSİ.
 *
 * Eşleme, var olan `device_identities` tablosunda tutulur (kind = fingerprint, identifier =
 * cihazdaki kullanıcı numarası). Yeni paralel bir eşleme tablosu kurulmaz.
 */
class ZkDeviceService
{
    /** Cihazın LAN bağlantı bilgilerini kaydeder. İletişim şifresi şifreli sütuna yazılır. */
    public function saveConnection(Device $device, array $data): void
    {
        $device->fill([
            'protocol' => 'zk',
            'zk_ip' => $data['ip'],
            'zk_port' => (int) ($data['port'] ?? config('devices_zk.port', 4370)),
            'zk_transport' => ($data['transport'] ?? 'tcp') === 'udp' ? 'udp' : 'tcp',
        ]);

        // Alan gönderilmediyse mevcut şifre korunur; boş string gönderildiyse şifre kaldırılır.
        if (array_key_exists('comm_key', $data)) {
            $device->zk_comm_key = $data['comm_key'] === '' ? null : $data['comm_key'];
        }

        $device->save();

        Audit::log('device.zk_connection_saved', "\"{$device->name}\" cihazının terminal bağlantı bilgilerini güncelledi (#{$device->id}).", $device);
    }

    /**
     * Bağlantı testi. Cihaza ulaşılamazsa istisna FIRLATMAZ; sonucu Türkçe açıklamayla döner,
     * böylece ekran "ne yapmalıyım?" bilgisini gösterebilir.
     *
     * @return array{durum:string, mesaj:string, oneri?:string, cihaz?:array, son_kayitlar?:array}
     */
    public function test(ZkConnectionSettings $settings, int $sampleSize = 5): array
    {
        $terminal = null;

        try {
            $terminal = ZkTerminal::open($settings);
            $info = $terminal->info();
            $records = $terminal->attendance(null, 500);
            $last = array_slice($records, -$sampleSize);

            return [
                'durum' => 'ok',
                'mesaj' => 'Cihaza bağlanıldı.',
                'hedef' => $settings->label(),
                'cihaz' => $info->toArray(),
                'son_kayitlar' => array_map(fn ($r) => $r->toArray(), $last),
                'toplam_kayit' => count($records),
            ];
        } catch (\App\Services\Devices\Zk\Exceptions\ZkException $e) {
            return ['durum' => 'hata', 'hedef' => $settings->label()] + $e->toArray();
        } finally {
            $terminal?->close();
        }
    }

    public function settingsFor(Device $device): ZkConnectionSettings
    {
        if (! $device->zk_ip) {
            throw new BusinessRuleException(
                "\"{$device->name}\" cihazının IP adresi girilmemiş. Önce cihaz bağlantı bilgilerini kaydedin.",
                'zk_missing_ip', ['device_id' => $device->id],
            );
        }

        // ZK paketleri YALNIZ ZK sürücüsü seçilmiş cihaza gider (ör. Perkotek YT33 / FK farklı protokol konuşur).
        if ($device->protocol !== null && $device->protocol !== 'zk') {
            throw new BusinessRuleException(
                "\"{$device->name}\" cihazının sürücüsü ZKTeco değil; bu işlem ZKTeco protokolüne özeldir. Terminal Köprüsü ekranındaki sürücü seçimini kullanın.",
                'terminal_driver_mismatch', ['device_id' => $device->id, 'protocol' => $device->protocol],
            );
        }

        return ZkConnectionSettings::fromDevice($device);
    }

    /**
     * Cihazdaki kullanıcılar + hâlihazırdaki eşleme + öğrenci önerisi.
     *
     * @return list<array<string, mixed>>
     */
    public function usersWithSuggestions(Device $device, ?ZkTerminal $terminal = null): array
    {
        $owned = $terminal === null;

        try {
            $terminal ??= ZkTerminal::open($this->settingsFor($device));
            $users = $terminal->users();
        } finally {
            if ($owned && isset($terminal)) {
                $terminal->close();
            }
        }

        return $this->decorate($users, $device->branch_id);
    }

    /**
     * @param  list<ZkUser>  $users
     * @return list<array<string, mixed>>
     */
    public function decorate(array $users, int $branchId): array
    {
        $identities = DeviceIdentity::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->where('kind', 'fingerprint')->where('person_type', 'student')
            ->pluck('person_id', 'identifier');

        $students = Student::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->whereIn('status', ['active', 'enrolled', 'frozen'])
            ->get(['id', 'student_no', 'full_name']);

        $byId = $students->keyBy('id');

        return array_map(function (ZkUser $user) use ($identities, $students, $byId) {
            $studentId = $identities[$user->userId] ?? null;
            $matched = $studentId ? $byId->get($studentId) : null;

            return [
                'kullanici_no' => $user->userId,
                'uid' => $user->uid,
                'ad' => $user->name,
                'kart' => $user->card,
                'yetki' => $user->privilegeLabel(),
                'eslesen_ogrenci' => $matched ? ['id' => $matched->id, 'ad' => $matched->full_name, 'no' => $matched->student_no] : null,
                'oneriler' => $matched ? [] : $this->suggest($user, $students),
            ];
        }, $users);
    }

    /**
     * Ad benzerliğine göre en iyi 3 öğrenci önerisi. Karar İNSANA aittir; otomatik eşleme yapılmaz
     * (yanlış eşleme yanlış öğrencinin velisine "giriş yaptı" bildirimi gönderir).
     *
     * @return list<array{id:int, ad:string, no:string, benzerlik:int}>
     */
    private function suggest(ZkUser $user, $students): array
    {
        $needle = $this->normalize($user->name);

        if ($needle === '') {
            return [];
        }

        $scored = [];

        foreach ($students as $student) {
            $percent = 0.0;
            similar_text($needle, $this->normalize($student->full_name), $percent);

            if ($percent >= 60) {
                $scored[] = ['id' => $student->id, 'ad' => $student->full_name, 'no' => $student->student_no, 'benzerlik' => (int) round($percent)];
            }
        }

        usort($scored, fn ($a, $b) => $b['benzerlik'] <=> $a['benzerlik']);

        return array_slice($scored, 0, 3);
    }

    /** Türkçe harfleri sadeleştirip karşılaştırmaya hazırlar ("Ayşe Yılmaz" ≈ "Ayse Yilmaz"). */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u']);

        return preg_replace('/[^a-z0-9 ]/', '', $value) ?? '';
    }

    /** Cihaz kullanıcı numarasını öğrenciye bağlar (device_identities). */
    public function link(int $branchId, string $deviceUserId, int $studentId): DeviceIdentity
    {
        $student = Student::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->find($studentId);

        if (! $student) {
            throw new BusinessRuleException('Öğrenci bu şubede bulunamadı.', 'student_not_found', [], 404);
        }

        $identity = DeviceIdentity::query()->withoutGlobalScope('branch')->updateOrCreate(
            ['branch_id' => $branchId, 'kind' => 'fingerprint', 'identifier' => $deviceUserId],
            ['person_type' => 'student', 'person_id' => $student->id, 'is_active' => true],
        );

        Audit::log('device_identity.upserted', "{$student->full_name} öğrencisini {$deviceUserId} numaralı cihaz kullanıcısına bağladı.", $student);

        return $identity;
    }

    public function unlink(DeviceIdentity $identity): void
    {
        Audit::log('device_identity.deleted', "Cihaz kullanıcı eşlemesini kaldırdı ({$identity->identifier}).", $identity);
        $identity->delete();
    }

    /** Ekranda "bekleyen/eşleşmemiş okutmalar" için şube kimliği. */
    public function branchId(?Device $device = null): int
    {
        return $device?->branch_id ?? app(BranchContext::class)->require();
    }
}
