<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\AttendanceEvent;
use App\Models\Student;
use App\Services\Attendance\DuplicateEventException;
use App\Services\Attendance\PresenceService;
use App\Support\Audit;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Canlı giriş/çıkış ekranı: şu an kurumda olanlar, son olaylar akışı, eşleşmeyen
 * okutmalar, manuel giriş/çıkış ve kiosk QR okuma (yönetici oturumuyla).
 */
class LivePresenceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();

        $inside = DB::table('daily_presences as dp')
            ->join('students as st', 'st.id', '=', 'dp.student_id')
            ->leftJoin('class_group_student as cgs', fn ($j) => $j->on('cgs.student_id', '=', 'st.id')->whereNull('cgs.left_on'))
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'cgs.class_group_id')
            ->where('dp.branch_id', $branchId)->where('dp.date', now()->toDateString())->where('dp.is_inside', true)
            ->orderBy('dp.first_entry_at')
            ->get(['st.id', 'st.full_name', 'st.student_no', 'st.photo_path', 'dp.first_entry_at', 'dp.minutes_inside', 'cg.name as class_group'])
            ->unique('id')->values();

        return response()->json([
            'inside_count' => $inside->count(),
            'inside' => $inside,
            'server_time' => now()->toAtomString(),
        ]);
    }

    /** Son olaylar akışı: ?after_id= ile kısa yoklama (3-5 sn). */
    public function feed(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $afterId = $request->integer('after_id', 0);

        $rows = AttendanceEvent::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)
            ->when($afterId, fn ($q) => $q->where('id', '>', $afterId), fn ($q) => $q->where('occurred_at', '>=', now()->subHours(2)))
            ->whereIn('event_type', ['ENTRY', 'EXIT'])
            ->with(['student:id,full_name,photo_path', 'device:id,name'])
            ->orderBy('id')->limit(100)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (AttendanceEvent $e) => [
                'id' => $e->id, 'event_type' => $e->event_type, 'source' => $e->source, 'occurred_at' => $e->occurred_at,
                'is_matched' => $e->is_matched, 'student' => $e->student ? ['id' => $e->student->id, 'full_name' => $e->student->full_name, 'photo_path' => $e->student->photo_path] : null,
                'device' => $e->device?->name,
            ]),
            'last_id' => $rows->max('id') ?? $afterId,
            'server_time' => now()->toAtomString(),
        ]);
    }

    /** Kimliği bilinmeyen okutmalar: cihaz kullanıcı no/kart tanınmadı. */
    public function unmatched(Request $request): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();

        $rows = AttendanceEvent::query()->withoutGlobalScope('branch')
            ->where('branch_id', $branchId)->where('is_matched', false)->whereNotNull('raw_identifier')
            ->with('device:id,name')
            ->orderByDesc('occurred_at')->limit(100)
            ->get(['id', 'device_id', 'event_type', 'source', 'occurred_at', 'raw_identifier']);

        return response()->json(['data' => $rows]);
    }

    /** Eşleşmeyen okutmayı bir öğrenciye bağlar (gelecekteki okutmalar da otomatik eşleşir). */
    public function match(Request $request, AttendanceEvent $event, PresenceService $presence): JsonResponse
    {
        $data = $request->validate(['student_id' => ['required', 'integer', Rule::exists('students', 'id')]]);
        $student = Student::query()->findOrFail($data['student_id']);

        $result = $presence->matchEvent($event, $student, $request->user()->id);

        return response()->json(['message' => "{$student->full_name} ile eşleştirildi.", 'data' => $result]);
    }

    /** Manuel giriş/çıkış (yönetici): öğrenciyi ara, giriş/çıkış kaydet. */
    public function manual(Request $request, PresenceService $presence): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')],
            'event_type' => ['required', Rule::in(['ENTRY', 'EXIT'])],
            'occurred_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $result = $presence->ingest([
                'student_id' => $data['student_id'],
                'event_type' => $data['event_type'],
                'occurred_at' => $data['occurred_at'] ?? now()->toAtomString(),
                'idempotency_key' => $data['idempotency_key'] ?? (string) Str::uuid(),
                'source' => 'manual',
            ]);
        } catch (DuplicateEventException) {
            return response()->json(['status' => 'duplicate', 'message' => 'Bu işlem zaten kaydedilmiş.']);
        }

        if ($result['status'] === 'accepted') {
            Audit::log('presence.manual', ($result['event_type'] === 'ENTRY' ? 'Manuel giriş kaydetti: ' : 'Manuel çıkış kaydetti: ').$result['student']);
        }

        return response()->json(['message' => 'Kaydedildi.', 'data' => $result]);
    }

    /**
     * Kiosk QR okuma — şimdilik yöneticinin/görevli personelin oturumuyla çalışır.
     * Gerçek kiosk dağıtımı için ayrı, oturumsuz bir rota gerekir (bkz. proje raporu).
     */
    public function scan(Request $request, PresenceService $presence): JsonResponse
    {
        $data = $request->validate(['value' => ['required', 'string', 'max:120']]);

        try {
            $result = $presence->ingest([
                'identifier' => $data['value'],
                'identifier_kind' => 'qr',
                'event_type' => 'AUTO',
                'occurred_at' => CarbonImmutable::now()->toAtomString(),
                'idempotency_key' => (string) Str::uuid(),
                'source' => 'qr',
            ]);
        } catch (DuplicateEventException) {
            return response()->json(['status' => 'duplicate', 'message' => 'Bu okutma zaten işlendi.']);
        }

        if ($result['status'] === 'unmatched') {
            return response()->json(['status' => 'unmatched', 'message' => 'Bu QR koda ait öğrenci bulunamadı.'], 200);
        }
        if ($result['status'] === 'debounced') {
            return response()->json(['status' => 'debounced', 'message' => "{$result['student']}: az önce okutuldu."]);
        }

        return response()->json([
            'status' => $result['status'],
            'message' => $result['event_type'] === 'ENTRY' ? "Hoş geldin {$result['student']}" : "Güle güle {$result['student']}",
            'data' => $result,
        ]);
    }
}
