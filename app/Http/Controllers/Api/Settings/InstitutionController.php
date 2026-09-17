<?php

namespace App\Http\Controllers\Api\Settings;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Models\Branch;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Kurum ayarları: KV grupları (institution/attendance/finance/retention/backup) + akademik dönemler + şubeler.
 */
class InstitutionController extends ApiController
{
    private const GROUP_RULES = [
        'institution' => [
            'name' => ['required', 'string', 'max:190'], 'short_name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:190'],
            'website' => ['nullable', 'string', 'max:190'], 'address' => ['nullable', 'string', 'max:500'],
            'tax_office' => ['nullable', 'string', 'max:120'], 'tax_number' => ['nullable', 'string', 'max:30'],
            'currency' => ['required', 'string', 'max:6'], 'timezone' => ['required', 'string', 'max:60'],
        ],
        'attendance' => [
            'late_after_minutes' => ['required', 'integer', 'min:0', 'max:180'], 'absent_after_minutes' => ['required', 'integer', 'min:0', 'max:300'],
            'auto_absence_enabled' => ['required', 'boolean'], 'notify_guardian_entry' => ['required', 'boolean'],
            'notify_guardian_exit' => ['required', 'boolean'], 'notify_guardian_absent_delay_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'min_minutes_between_events' => ['required', 'integer', 'min:0', 'max:60'],
            'pause_when_no_device_data' => ['sometimes', 'boolean'], 'device_stale_minutes' => ['sometimes', 'integer', 'min:5', 'max:720'],
        ],
        'finance' => [
            'reminder_offsets' => ['required', 'array'], 'reminder_offsets.*' => ['integer', 'min:-30', 'max:30'],
            'reminders_enabled' => ['required', 'boolean'], 'reminder_hour' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            // Belge numarası önekleri: MKB-2026-000123. Sayaç önekten bağımsızdır; önek değişse de numara tekrar etmez.
            'receipt_prefix' => ['sometimes', 'string', 'min:2', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'enrollment_prefix' => ['sometimes', 'string', 'min:2', 'max:10', 'regex:/^[A-Z0-9]+$/'],
        ],
        'retention' => [
            'attendance_events_days' => ['required', 'integer', 'min:30', 'max:3650'], 'outbound_messages_days' => ['required', 'integer', 'min:30', 'max:3650'],
            'login_events_days' => ['required', 'integer', 'min:30', 'max:3650'], 'withdrawn_student_anonymize_after_days' => ['required', 'integer', 'min:365', 'max:7300'],
        ],
        'portal' => [
            'guardian_requests_enabled' => ['sometimes', 'boolean'],
            'show_student_overdue_alert' => ['sometimes', 'boolean'],
            'show_guardian_overdue_alert' => ['sometimes', 'boolean'],
        ],
        'backup' => [
            'daily_enabled' => ['required', 'boolean'], 'daily_keep' => ['required', 'integer', 'min:1', 'max:90'], 'weekly_keep' => ['required', 'integer', 'min:1', 'max:52'],
        ],
    ];

    /** Ayar alanlarının Türkçe adları (doğrulama mesajlarında) */
    private const ATTRIBUTES = [
        'name' => 'Ad', 'short_name' => 'Kısa ad', 'website' => 'Web sitesi', 'tax_office' => 'Vergi dairesi', 'tax_number' => 'Vergi numarası',
        'currency' => 'Para birimi', 'timezone' => 'Saat dilimi', 'code' => 'Şube kodu',
        'late_after_minutes' => 'Geç sayılma süresi', 'absent_after_minutes' => 'Gelmedi sayılma süresi',
        'notify_guardian_absent_delay_minutes' => 'Veli bildirim gecikmesi', 'min_minutes_between_events' => 'Okutmalar arası en az süre',
        'device_stale_minutes' => 'Cihaz sessizlik süresi', 'reminder_offsets' => 'Hatırlatma günleri', 'reminder_offsets.*' => 'Hatırlatma günü',
        'reminder_hour' => 'Hatırlatma saati', 'receipt_prefix' => 'Makbuz öneki', 'enrollment_prefix' => 'Kayıt öneki',
        'attendance_events_days' => 'Yoklama kayıtları saklama süresi', 'outbound_messages_days' => 'Gönderilen mesajlar saklama süresi',
        'login_events_days' => 'Giriş denemeleri saklama süresi', 'withdrawn_student_anonymize_after_days' => 'Anonimleştirme süresi',
        'daily_keep' => 'Günlük yedek sayısı', 'weekly_keep' => 'Haftalık yedek sayısı', 'starts_on' => 'Başlangıç tarihi', 'ends_on' => 'Bitiş tarihi',
    ];

    private const GROUP_LABELS = [
        'institution' => 'Kurum bilgileri', 'attendance' => 'Yoklama kuralları', 'finance' => 'Ödeme hatırlatma kuralları',
        'retention' => 'KVKK saklama süreleri', 'backup' => 'Yedekleme politikası', 'portal' => 'Portal ayarları',
    ];

    public function show(string $group): JsonResponse
    {
        if (! isset(self::GROUP_RULES[$group])) {
            abort(404);
        }
        $values = Settings::group($group);
        if ($group === 'institution' && $values['logo_path']) {
            $values['logo_url'] = Storage::disk('public')->url($values['logo_path']);
        }

        return response()->json($values);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        if (! isset(self::GROUP_RULES[$group])) {
            abort(404);
        }
        foreach (['receipt_prefix', 'enrollment_prefix'] as $k) {
            if ($group === 'finance' && is_string($request->input($k))) {
                $request->merge([$k => mb_strtoupper(trim($request->input($k)))]);
            }
        }
        if ($group === 'institution' && $request->filled('timezone') && ! in_array($request->input('timezone'), \DateTimeZone::listIdentifiers(), true)) {
            throw new BusinessRuleException('Geçerli bir saat dilimi seçin (ör. Europe/Istanbul).', 'invalid_timezone');
        }
        $data = $request->validate(self::GROUP_RULES[$group], [
            'receipt_prefix.regex' => 'Makbuz öneki yalnız büyük harf ve rakam içerebilir.',
            'enrollment_prefix.regex' => 'Kayıt öneki yalnız büyük harf ve rakam içerebilir.',
            'reminder_hour.regex' => 'Hatırlatma saati SS:DD biçiminde olmalı (ör. 10:00).',
        ], self::ATTRIBUTES);
        if (isset($data['reminder_offsets'])) {
            $data['reminder_offsets'] = array_values(array_unique(array_map('intval', $data['reminder_offsets'])));
            sort($data['reminder_offsets']);
        }
        $before = Settings::group($group);
        Settings::put($group, $data);
        $after = Settings::group($group);
        Audit::log('settings.updated', self::GROUP_LABELS[$group].' ayarlarını güncelledi.', null, ['before' => $before, 'after' => $after]);

        return response()->json(Settings::group($group));
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'max:2048']]);
        $path = $request->file('logo')->store('institution', 'public');
        $old = Settings::get('institution.logo_path');
        Settings::put('institution', ['logo_path' => $path]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }
        Audit::log('settings.logo_updated', 'kurum logosunu güncelledi.');

        return response()->json(['logo_url' => Storage::disk('public')->url($path)]);
    }

    // --- Akademik dönemler ---

    public function terms(): JsonResponse
    {
        return response()->json(AcademicTerm::query()->orderByDesc('starts_on')->get());
    }

    public function termsStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after:starts_on'],
            'is_current' => ['sometimes', 'boolean'],
        ], ['ends_on.after' => 'Bitiş tarihi başlangıç tarihinden sonra olmalı.'], ['name' => 'Dönem adı'] + self::ATTRIBUTES);
        $term = DB::transaction(function () use ($data) {
            if (! empty($data['is_current'])) {
                AcademicTerm::query()->update(['is_current' => false]);
            }

            return AcademicTerm::query()->create($data);
        });
        Audit::log('settings.term_created', "{$term->name} dönemini oluşturdu.");

        return response()->json($term, 201);
    }

    public function termsUpdate(Request $request, AcademicTerm $term): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'], 'starts_on' => ['sometimes', 'date'], 'ends_on' => ['sometimes', 'date', 'after:starts_on'],
        ]);
        $term->update($data);
        Audit::log('settings.term_updated', "{$term->name} dönemini güncelledi.");

        return response()->json($term);
    }

    public function termsSetCurrent(AcademicTerm $term): JsonResponse
    {
        DB::transaction(function () use ($term) {
            AcademicTerm::query()->update(['is_current' => false]);
            $term->forceFill(['is_current' => true])->save();
        });
        Audit::log('settings.term_set_current', "{$term->name} dönemini geçerli dönem yaptı.");

        return response()->json($term);
    }

    // --- Şubeler ---

    public function branches(): JsonResponse
    {
        return response()->json(Branch::query()->withCount('users')->orderBy('name')->get());
    }

    public function branchesStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')], 'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:80'],
        ], ['code.unique' => 'Bu şube kodu zaten kullanılıyor.'], ['name' => 'Şube adı'] + self::ATTRIBUTES);
        $branch = Branch::query()->create($data);
        Audit::log('settings.branch_created', "{$branch->name} şubesini oluşturdu.");

        return response()->json($branch, 201);
    }

    public function branchesUpdate(Request $request, Branch $branch): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:190'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'], 'city' => ['nullable', 'string', 'max:80'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        if (($data['is_active'] ?? true) === false && Branch::query()->where('is_active', true)->count() <= 1) {
            throw new BusinessRuleException('Son aktif şube pasife alınamaz.', 'last_active_branch');
        }
        $branch->update($data);
        Audit::log('settings.branch_updated', "{$branch->name} şube bilgilerini güncelledi.");

        return response()->json($branch);
    }

    // --- Kurulum sihirbazı ---

    public function onboardingStatus(): JsonResponse
    {
        return response()->json([
            'institution' => Settings::group('institution'),
            'counts' => [
                'subjects' => DB::table('subjects')->count(),
                'classrooms' => DB::table('classrooms')->count(),
                'teachers' => DB::table('teachers')->whereNull('deleted_at')->count(),
                'students' => DB::table('students')->whereNull('deleted_at')->count(),
            ],
        ]);
    }

    public function onboardingComplete(): JsonResponse
    {
        Settings::put('institution', ['onboarding_completed' => true]);
        Audit::log('settings.onboarding_completed', 'kurulum sihirbazını tamamladı.');

        return $this->ok('Kurulum tamamlandı.');
    }
}
