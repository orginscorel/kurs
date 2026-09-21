<?php

namespace App\Services\Attendance;

use App\Exceptions\BusinessRuleException;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Employee;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Devices\WebPanel\WebPanelDriver;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * TERMİNALE KAYIT SİHİRBAZI — kursta kayıtlı bir kişiyi parmak izi / kart / yüz terminaline kaydettirme.
 *
 * Parmak, kart ve yüz her durumda kişi cihazın başındayken cihaza okutulur; sistem şunu yapar:
 *   1. start(): kişiye cihaz numarası ayırır (sıralı, 1001'den) ve PDKS eşlemesini hemen kurar; bir kayıt oturumu açar.
 *   2. Görevli cihaz menüsünde bu numarayla "Yeni kullanıcı" açar, kişi okutur, cihaz kaydeder.
 *   3. Cihaz "realtime_enroll_data" push'unu gönderir → RealtimeIngest → onEnroll(): oturum tamamlanır.
 *      Cihaz numarayı kendisi verdiyse (görevli ayrılan numarayı yazmadıysa) ve o numara boştaysa eşleme o numaraya taşınır.
 * Oturumlar LOCAL (yalnız cihazı dinleyen Mac); aynı şubede aynı anda tek açık oturum olur (eşleştirmede karışıklık olmasın).
 */
class TerminalEnrollmentService
{
    public const TABLE = 'terminal_enrollment_sessions';

    public const FIRST_NO = 1001;

    public const MAX_AUTO_NO = 99999;

    private const MINUTES = 15;

    public function __construct(
        private readonly PdksService $pdks,
        private readonly WebPanelDriver $panel,
    ) {}

    /** Bu şubede web paneli (IP + panel kullanıcı/şifre) tanımlı, cihaza yazılabilir ilk etkin cihaz. */
    public function panelDevice(int $branchId): ?Device
    {
        return Device::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
            ->where('is_active', true)->whereNull('deleted_at')
            ->whereNotNull('zk_ip')->whereNotNull('panel_user')->whereNotNull('panel_password_encrypted')
            ->orderBy('id')->get()->first(fn (Device $d) => $d->supportsWebPanel());
    }

    /**
     * Kişiyi cihazda AÇ (SetUserInfo, no + ad) ve cihazı kayıt ekranına GEÇİR (EnterEnroll fp/face).
     * Fiziksel okutma bitince cihaz enroll push'u gönderir → RealtimeIngest → onEnroll oturumu tamamlar.
     * Cihaza ulaşılamazsa/başarısızsa oturum açık kalır; kullanıcı elle talimatı görür.
     *
     * @return array{baslatildi:bool, ozellik?:string, asama?:string, mesaj:string, oneri?:string, oturum:array}
     */
    public function deviceStart(int $branchId, int $sessionId, string $feature): array
    {
        $session = $this->find($branchId, $sessionId);
        $person = $this->pdks->findPerson($branchId, $session->person_type, (int) $session->person_id);

        if ($session->completed_at) {
            return ['baslatildi' => true, 'mesaj' => 'Bu kişi zaten cihaza kaydedilmiş.', 'oturum' => $this->present($session, $person)];
        }

        $device = $this->panelDevice($branchId);
        if (! $device) {
            throw new BusinessRuleException(
                'Bu şubede web paneli tanımlı bir cihaz yok. Cihaza otomatik yazmak için Yoklama › Cihazlar › Web paneli ayarından IP, panel kullanıcı adı ve şifresini girin.',
                'panel_device_yok', [], 422,
            );
        }

        $no = (string) $session->reserved_no;

        $set = $this->panel->upsertUser($device, $no, (string) $person['ad']);
        if (! $set->isOk()) {
            return ['baslatildi' => false, 'asama' => 'kullanici', 'mesaj' => $set->message, 'oneri' => $set->hint, 'oturum' => $this->present($session, $person)];
        }

        $enroll = $this->panel->enterEnroll($device, $no, $feature);
        if (! $enroll->isOk()) {
            return ['baslatildi' => false, 'asama' => 'kayit', 'mesaj' => $enroll->message, 'oneri' => $enroll->hint, 'oturum' => $this->present($session, $person)];
        }

        DB::table(self::TABLE)->where('id', $session->id)->update(['device_id' => $device->id, 'updated_at' => now()]);
        Audit::log('terminal_enrollment.device_started', "{$person['ad']} için cihazda {$feature} kaydı başlatıldı (no {$no}, cihaz #{$device->id}).");

        return [
            'baslatildi' => true,
            'ozellik' => $feature,
            'mesaj' => 'Cihaz kayıt ekranına geçti. Kişi şimdi '.($feature === 'face' ? 'yüzünü okutsun' : 'parmağını okutsun').'; kayıt bitince bu pencere kendiliğinden görecek.',
            'oturum' => $this->present($this->find($branchId, $sessionId), $person),
        ];
    }

    /** Kişi için kayıt oturumu açar (varsa mevcut numarasını kullanır — ek parmak / kart / yüz kaydı). */
    public function start(int $branchId, string $type, int $personId): array
    {
        $person = $this->pdks->findPerson($branchId, $type, $personId);

        $session = DB::transaction(function () use ($branchId, $type, $personId) {
            // Önceki açık oturumları kapat (tek açık oturum); tamamlanmamış yeni ayrılan numaraları geri al
            DB::table(self::TABLE)->where('branch_id', $branchId)->whereNull('completed_at')->where('expires_at', '>', now())
                ->orderBy('id')->get()->each(fn ($s) => $this->release($s));

            $existing = DeviceIdentity::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
                ->where('kind', 'fingerprint')->where('person_type', $type)->where('person_id', $personId)->orderBy('id')->first();

            $no = $existing?->identifier ?? $this->nextNumber($branchId);

            if (! $existing) {
                $this->pdks->link($branchId, $no, $type, $personId, false, 'fingerprint', false);
            }

            $id = DB::table(self::TABLE)->insertGetId([
                'branch_id' => $branchId, 'person_type' => $type, 'person_id' => $personId,
                'reserved_no' => $no, 'created_identity' => ! $existing,
                'expires_at' => now()->addMinutes(self::MINUTES), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table(self::TABLE)->find($id);
        });

        Audit::log('terminal_enrollment.started', "{$person['ad']} için terminal kaydı başlattı (cihaz no {$session->reserved_no}).");

        return $this->present($session, $person);
    }

    public function status(int $branchId, int $sessionId): array
    {
        $session = $this->find($branchId, $sessionId);

        return $this->present($session, $this->pdks->findPerson($branchId, $session->person_type, (int) $session->person_id));
    }

    /** Vazgeç: bu oturumda ayrılan numara cihaza hiç kaydedilmediyse eşleme geri alınır. */
    public function cancel(int $branchId, int $sessionId): void
    {
        $session = $this->find($branchId, $sessionId);

        if (! $session->completed_at) {
            DB::transaction(fn () => $this->release($session));
        }
    }

    /** Süresi uzatılır (kişi sırada bekliyorsa). */
    public function extend(int $branchId, int $sessionId): array
    {
        $session = $this->find($branchId, $sessionId);
        DB::table(self::TABLE)->where('id', $session->id)->update(['expires_at' => now()->addMinutes(self::MINUTES), 'updated_at' => now()]);

        return $this->status($branchId, $sessionId);
    }

    /**
     * Cihazdan kullanıcı kaydı geldi (RealtimeIngest çağırır). Açık oturumla eşleşirse tamamlar ve not döner.
     * Eşleşme kuralı: gelen numara ayrılan numaraysa → tamam. Farklıysa ve bu numara hiç kimseye bağlı değilse →
     * cihaz kendi numarasını vermiştir; eşleme gelen numaraya taşınır. Başka birine bağlı numaraya DOKUNULMAZ.
     */
    public function onEnroll(Device $device, string $userNo): ?string
    {
        $branchId = (int) $device->branch_id;
        $session = DB::table(self::TABLE)->where('branch_id', $branchId)->whereNull('completed_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->first();

        if (! $session) {
            return null;
        }

        $owner = DeviceIdentity::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
            ->where('kind', 'fingerprint')->where('identifier', $userNo)->first();
        $samePerson = $owner && $owner->person_type === $session->person_type && (int) $owner->person_id === (int) $session->person_id;

        if ($userNo !== $session->reserved_no) {
            if ($owner && ! $samePerson) {
                return null;   // başka birinin kaydı (ör. yeniden parmak ekleme) — oturum açık kalır
            }

            // Cihaz başka numara verdi: ayrılan boş numarayı bırak, eşlemeyi gerçek numaraya kur
            if ($session->created_identity) {
                DeviceIdentity::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->where('kind', 'fingerprint')
                    ->where('identifier', $session->reserved_no)->where('person_type', $session->person_type)->where('person_id', $session->person_id)
                    ->delete();
            }
            if (! $owner) {
                $this->pdks->link($branchId, $userNo, $session->person_type, (int) $session->person_id, false, 'fingerprint', true);
            }
        }

        DB::table(self::TABLE)->where('id', $session->id)->update([
            'final_no' => $userNo, 'device_id' => $device->id, 'completed_at' => now(), 'updated_at' => now(),
        ]);

        $name = $this->pdks->findPerson($branchId, $session->person_type, (int) $session->person_id)['ad'];

        return "Kayıt sihirbazı: {$name} kişisine bağlandı".($userNo !== $session->reserved_no ? " (cihaz {$userNo} numarasını verdi)." : '.');
    }

    /**
     * Terminale kaydı olmayan kişiler (toplu kayıt günü listesi): etkin öğrenci/öğretmen/çalışan, parmak eşlemesi yok.
     *
     * @return array{toplam:int, kisiler:list<array{kisi_turu:string, kisi_id:int, ad:string, no:?string, etiket:string}>}
     */
    public function unenrolled(int $branchId, ?string $type = null, ?string $q = null, int $limit = 300): array
    {
        $linked = DeviceIdentity::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->where('kind', 'fingerprint')
            ->get(['person_type', 'person_id'])->map(fn ($i) => $i->person_type.':'.$i->person_id)->flip();
        $q = trim((string) $q);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $out = [];

        if (! $type || $type === 'student') {
            $query = Student::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)
                ->whereNotIn('status', ['withdrawn', 'graduated'])->orderBy('full_name');
            if ($q !== '') {
                $query->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('student_no', 'like', $like));
            }
            foreach ($query->get(['id', 'full_name', 'student_no']) as $s) {
                if (! isset($linked['student:'.$s->id])) {
                    $out[] = ['kisi_turu' => 'student', 'kisi_id' => $s->id, 'ad' => $s->full_name, 'no' => $s->student_no, 'etiket' => 'Öğrenci'];
                }
            }
        }

        foreach (['teacher' => Teacher::class, 'employee' => Employee::class] as $t => $model) {
            if ($type && $type !== $t) {
                continue;
            }
            $query = $model::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->where('is_active', true)->orderBy('first_name');
            if ($q !== '') {
                $query->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
            }
            foreach ($query->get(['id', 'first_name', 'last_name']) as $p) {
                if (! isset($linked[$t.':'.$p->id])) {
                    $out[] = ['kisi_turu' => $t, 'kisi_id' => $p->id, 'ad' => trim($p->first_name.' '.$p->last_name), 'no' => null, 'etiket' => PdksService::PERSON_TYPES[$t]];
                }
            }
        }

        return ['toplam' => count($out), 'kisiler' => array_slice($out, 0, $limit)];
    }

    /** Sıradaki boş cihaz numarası: 1001–99999 arasındaki en büyük kullanılan + 1 (eşlemeler + cihazdan gelen kullanıcılar). */
    public function nextNumber(int $branchId): string
    {
        $used = DeviceIdentity::query()->withoutGlobalScope('branch')->where('branch_id', $branchId)->where('kind', 'fingerprint')
            ->pluck('identifier')->all();

        $used = array_merge($used, DB::table('terminal_device_users')->where('branch_id', $branchId)->pluck('user_no')->all());
        $numbers = array_filter(array_map(fn ($v) => ctype_digit((string) $v) ? (int) $v : 0, $used), fn ($n) => $n >= self::FIRST_NO && $n <= self::MAX_AUTO_NO);
        $next = $numbers ? max($numbers) + 1 : self::FIRST_NO;
        $taken = array_flip(array_map('strval', $used));

        while (isset($taken[(string) $next])) {
            $next++;
        }

        return (string) $next;
    }

    // ------------------------------------------------------------------

    private function release(object $session): void
    {
        DB::table(self::TABLE)->where('id', $session->id)->update(['expires_at' => now(), 'updated_at' => now()]);

        $onDevice = DB::table('terminal_device_users')->where('branch_id', $session->branch_id)->where('user_no', $session->reserved_no)->exists();
        if ($session->created_identity && ! $onDevice) {
            DeviceIdentity::query()->withoutGlobalScope('branch')->where('branch_id', $session->branch_id)->where('kind', 'fingerprint')
                ->where('identifier', $session->reserved_no)->where('person_type', $session->person_type)->where('person_id', $session->person_id)
                ->delete();
        }
    }

    private function find(int $branchId, int $id): object
    {
        $session = DB::table(self::TABLE)->where('branch_id', $branchId)->where('id', $id)->first();

        if (! $session) {
            throw new BusinessRuleException('Kayıt oturumu bulunamadı.', 'enrollment_not_found', [], 404);
        }

        return $session;
    }

    private function present(object $session, array $person): array
    {
        $no = $session->final_no ?? $session->reserved_no;
        $user = DB::table('terminal_device_users')->where('branch_id', $session->branch_id)->where('user_no', $no)
            ->orderByDesc('last_enrolled_at')->first();
        $lastPunch = DB::table('attendance_events')->where('branch_id', $session->branch_id)->where('raw_identifier', $no)->max('occurred_at');

        $state = match (true) {
            $session->completed_at !== null => 'kaydedildi',
            now()->greaterThanOrEqualTo($session->expires_at) => 'suresi_doldu',
            default => 'bekliyor',
        };

        return [
            'id' => $session->id,
            'durum' => $state,
            'panel_var' => $this->panelDevice((int) $session->branch_id) !== null,
            'kisi' => $person['ad'],
            'kisi_no' => $person['no'],
            'kisi_turu' => $session->person_type,
            'kisi_turu_etiketi' => PdksService::PERSON_TYPES[$session->person_type] ?? $session->person_type,
            'cihaz_no' => $no,
            'ayrilan_no' => $session->reserved_no,
            'yeni_numara' => (bool) $session->created_identity,
            'bitis' => $session->expires_at,
            'tamamlandi' => $session->completed_at,
            'terminal' => $user ? [
                'ad' => $user->name,
                'parmak' => $user->fingerprint_count !== null ? (int) $user->fingerprint_count : null,
                'yuz' => (int) ($user->face_count ?? 0),
                'kart' => $user->card_no !== null && $user->card_no !== '' && $user->card_no !== '0',
                'zaman' => $user->last_enrolled_at,
            ] : null,
            'son_okutma' => $lastPunch,
        ];
    }
}
