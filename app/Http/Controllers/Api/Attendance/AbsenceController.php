<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Api\ApiController;
use App\Models\Attendance;
use App\Services\Attendance\AbsenceService;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AbsenceController extends ApiController
{
    public function __construct(private readonly AbsenceService $absences) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rows = $this->absences->paginate($filters, $this->perPage($request, 30));
        $threshold = max(1, $request->integer('threshold', 5));
        $window = max(1, $request->integer('window_days', 30));

        return $this->paginated($rows, null, [
            'summary' => $this->absences->studentSummary($filters),
            'over_threshold' => $this->absences->overThreshold($threshold, $window),
            'threshold' => $threshold,
            'window_days' => $window,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->absences->query($filters)->orderBy('attendances.date');
        Audit::log('attendance.exported', 'Devamsızlık listesini Excel olarak dışa aktardı.');

        return response()->streamDownload(function () use ($query) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['Tarih', 'Öğrenci No', 'Ad Soyad', 'Sınıf', 'Ders', 'Durum', 'Geç (dk)', 'Yöntem', 'Not']));

            $query->chunk(500, function ($chunk) use ($writer) {
                foreach ($chunk as $a) {
                    $writer->addRow(Row::fromValues([
                        $a->date->format('d.m.Y'), $a->student_no, $a->full_name, $a->class_group, $a->subject,
                        Attendance::STATUSES[$a->status] ?? $a->status, $a->late_minutes ?? '', $a->method, $a->note ?? '',
                    ]));
                }
            });
            $writer->close();
        }, 'devamsizlik-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** İzin/rapor girişi: tarih aralığı için öğrencinin derslerini toplu İZİNLİ/RAPORLU yapar. */
    public function leave(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['required', Rule::in(['excused', 'medical'])],
            'note' => ['nullable', 'string', 'max:300'],
        ], [], ['student_id' => 'Öğrenci', 'from' => 'Başlangıç tarihi', 'to' => 'Bitiş tarihi', 'status' => 'Durum', 'note' => 'Belge notu']);

        $count = $this->absences->bulkLeave($data['student_id'], $data['from'], $data['to'], $data['status'], $data['note'] ?? null, $request->user()->id);

        return response()->json(['message' => "{$count} ders için izin/rapor işlendi.", 'count' => $count]);
    }

    public function options(): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();

        return response()->json([
            'class_groups' => DB::table('class_groups')->where('branch_id', $branchId)->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => Attendance::STATUSES,
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'from' => $request->date('from')?->toDateString() ?? now()->subDays(30)->toDateString(),
            'to' => $request->date('to')?->toDateString() ?? now()->toDateString(),
            'class_group_id' => $request->integer('class_group_id') ?: null,
            // boş = yalnız gelmedi/geç/izinli/raporlu; 'all' = var dahil tümü
            'status' => in_array($request->query('status'), [...array_keys(Attendance::STATUSES), AbsenceService::ALL], true) ? $request->query('status') : null,
            'student_id' => $request->integer('student_id') ?: null,
        ];
    }
}
