<?php

namespace App\Http\Controllers\Api\Academic;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Api\ApiController;
use App\Models\CalendarFeed;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\Academic\CalendarService;
use App\Services\Academic\SchedulePdf;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Birleşik takvim olayları, haftalık program PDF'i ve iCal abonelik bağlantıları. */
class CalendarController extends ApiController
{
    public function __construct(private readonly CalendarService $calendar) {}

    public function events(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date'],
            'types' => ['nullable', 'array'], 'types.*' => [Rule::in(CalendarService::TYPES)],
            'class_group_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'], 'classroom_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->calendar->events(CarbonImmutable::parse($data['from']), CarbonImmutable::parse($data['to']), $data, $request->user()));
    }

    public function pdf(Request $request, SchedulePdf $pdf): Response
    {
        $data = $request->validate(['view' => ['required', Rule::in(['class_group', 'teacher', 'classroom'])], 'id' => ['required', 'integer'], 'date' => ['nullable', 'date']]);

        return $pdf->weekly($data['view'], (int) $data['id'], isset($data['date']) ? CarbonImmutable::parse($data['date']) : CarbonImmutable::today());
    }

    /** Yeni iCal bağlantısı üretir; aynı sahip için önceki bağlantılar geçersiz olur. */
    public function createFeed(Request $request): JsonResponse
    {
        $data = $request->validate(['owner_type' => ['required', Rule::in(CalendarFeed::OWNER_TYPES)], 'owner_id' => ['required', 'integer']]);
        $user = $request->user();
        $name = match ($data['owner_type']) {
            'teacher' => Teacher::query()->findOrFail($data['owner_id'])->full_name,
            'student' => (function () use ($data, $user) {
                $s = Student::query()->findOrFail($data['owner_id']);
                $own = $user->user_type === 'student' && $s->user_id === $user->id;
                if (! $own && ! $user->can('students.view')) {
                    abort(403);
                }

                return $s->full_name;
            })(),
            'class_group' => ClassGroup::query()->findOrFail($data['owner_id'])->name,
            default => Classroom::query()->findOrFail($data['owner_id'])->name,
        };

        CalendarFeed::query()->where('owner_type', $data['owner_type'])->where('owner_id', $data['owner_id'])->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $token = Str::random(48);
        CalendarFeed::query()->create(['owner_type' => $data['owner_type'], 'owner_id' => $data['owner_id'], 'token_hash' => hash('sha256', $token), 'created_by' => $user?->id]);
        Audit::log('calendar.feed_created', "$name için takvim (iCal) bağlantısı oluşturdu; önceki bağlantılar geçersiz kılındı.");

        $url = url("/api/v1/calendar/ical/{$token}.ics");

        return response()->json([
            'message' => 'Takvim bağlantısı hazır. Bağlantıyı yalnızca ilgili kişiyle paylaşın.',
            'url' => $url, 'webcal_url' => preg_replace('#^https?://#', 'webcal://', $url), 'name' => $name,
        ], 201);
    }

    /** Sahibin etkin bağlantısı var mı (jeton bir daha gösterilmez; yalnız durum). */
    public function feedStatus(Request $request): JsonResponse
    {
        $data = $request->validate(['owner_type' => ['required', Rule::in(CalendarFeed::OWNER_TYPES)], 'owner_id' => ['required', 'integer']]);
        $feed = CalendarFeed::query()->where('owner_type', $data['owner_type'])->where('owner_id', $data['owner_id'])->whereNull('revoked_at')->latest('id')->first();

        return response()->json(['active' => (bool) $feed, 'created_at' => $feed?->created_at?->toIso8601String(), 'last_accessed_at' => $feed?->last_accessed_at?->toIso8601String(), 'access_count' => (int) ($feed?->access_count ?? 0)]);
    }

    public function revokeFeeds(Request $request): JsonResponse
    {
        $data = $request->validate(['owner_type' => ['required', Rule::in(CalendarFeed::OWNER_TYPES)], 'owner_id' => ['required', 'integer']]);
        $n = CalendarFeed::query()->where('owner_type', $data['owner_type'])->where('owner_id', $data['owner_id'])->whereNull('revoked_at')->update(['revoked_at' => now()]);
        if ($n === 0) {
            throw new BusinessRuleException('Etkin takvim bağlantısı yok.', 'no_feed');
        }
        Audit::log('calendar.feed_revoked', 'Takvim (iCal) bağlantısını iptal etti.');

        return $this->ok('Takvim bağlantısı iptal edildi.');
    }
}
