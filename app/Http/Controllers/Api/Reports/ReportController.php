<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\ApiController;
use App\Models\Attendance;
use App\Models\ClassGroup;
use App\Models\Lead;
use App\Models\Program;
use App\Models\Student;
use App\Services\Finance\FinanceDocuments;
use App\Services\Reports\ReportService;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Rapor merkezi uçları. Yetki rota katmanında: reports.view + ilgili modül izni; dışa aktarma ayrıca reports.export.
 * Finans raporu mevcut /finance/reports uçlarını (reports.finance) kullanır; burada tekrarlanmaz.
 */
class ReportController extends ApiController
{
    public function __construct(private readonly ReportService $reports) {}

    /** Filtre seçenekleri (sınıf, program, kaynak). */
    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'class_groups' => ClassGroup::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']),
            'programs' => Program::query()->orderBy('name')->get(['id', 'name']),
            'student_statuses' => Student::STATUSES,
            'lead_sources' => Lead::SOURCES,
        ]);
    }

    /* ---------------------------------------------------------------- Öğrenciler */

    private function studentFilters(Request $request): array
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Student::STATUSES))],
            'program_id' => ['nullable', 'integer'], 'class_group_id' => ['nullable', 'integer'],
            'school_grade' => ['nullable', 'string', 'max:30'], 'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            'status' => $request->query('status') ?: null, 'program_id' => $request->integer('program_id') ?: null,
            'class_group_id' => $request->integer('class_group_id') ?: null, 'school_grade' => $request->query('school_grade') ?: null,
            'q' => trim((string) $request->query('q')) ?: null, 'from' => $request->query('from') ?: null, 'to' => $request->query('to') ?: null,
        ];
    }

    public function students(Request $request): JsonResponse
    {
        $f = $this->studentFilters($request);
        $sensitive = $request->user()->can('students.view_sensitive');
        /** @var LengthAwarePaginator $page */
        $page = $this->reports->studentQuery($f)->orderBy('s.full_name')->paginate($this->perPage($request, 50));

        return $this->paginated($page, fn ($r) => $this->reports->studentRow($r, $sensitive), ['summary' => $this->reports->studentSummary($f)]);
    }

    public function studentsExport(Request $request): StreamedResponse
    {
        $f = $this->studentFilters($request);
        $sensitive = $request->user()->can('students.view_sensitive');
        $query = $this->reports->studentQuery($f)->orderBy('s.full_name');
        Audit::log('report.students_exported', 'Öğrenci listesi raporunu Excel olarak dışa aktardı.');

        return $this->xlsx('ogrenci-raporu-'.now()->format('Y-m-d').'.xlsx',
            ['Öğrenci No', 'Ad Soyad', 'Durum', 'Sınıf', 'Program', 'Okul', 'Sınıf Seviyesi', 'Alan', 'Telefon', 'Kayıt Tarihi'],
            function (callable $add) use ($query, $sensitive) {
                $query->chunk(500, function ($chunk) use ($add, $sensitive) {
                    foreach ($chunk as $r) {
                        $x = $this->reports->studentRow($r, $sensitive);
                        $add([$x['student_no'], $x['full_name'], $x['status_label'], $x['class_names'] ?? '', $x['program_names'] ?? '', $x['school_name'] ?? '',
                            $x['school_grade'] ?? '', $x['field'] ?? '', $x['phone'] ?? '', $x['registered_on'] ? CarbonImmutable::parse($x['registered_on'])->format('d.m.Y') : '']);
                    }
                });
            });
    }

    /* ---------------------------------------------------------------- Yoklama */

    private function attendanceFilters(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'class_group_id' => ['nullable', 'integer'],
        ], ['to.after_or_equal' => 'Bitiş tarihi başlangıçtan önce olamaz.']);
        $to = $request->query('to') ?: CarbonImmutable::today()->toDateString();
        $from = $request->query('from') ?: CarbonImmutable::parse($to)->subDays(29)->toDateString();
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 400) {
            abort(response()->json(['message' => 'Tarih aralığı en fazla 400 gün olabilir.', 'errors' => ['from' => ['Tarih aralığı en fazla 400 gün olabilir.']]], 422));
        }

        return ['from' => $from, 'to' => $to, 'class_group_id' => $request->integer('class_group_id') ?: null];
    }

    public function attendance(Request $request): JsonResponse
    {
        $f = $this->attendanceFilters($request);

        return response()->json(['data' => $this->reports->attendance($f) + ['students' => $this->reports->attendanceStudents($f, 300)]]);
    }

    public function attendanceExport(Request $request): StreamedResponse
    {
        $f = $this->attendanceFilters($request);
        $rows = $this->reports->attendanceStudents($f);
        Audit::log('report.attendance_exported', sprintf('%s – %s devamsızlık raporunu Excel olarak dışa aktardı.', $this->d($f['from']), $this->d($f['to'])));

        return $this->xlsx("devamsizlik-raporu-{$f['from']}-{$f['to']}.xlsx",
            ['Öğrenci No', 'Ad Soyad', 'Sınıf', 'Kayıt', Attendance::STATUSES['present'], Attendance::STATUSES['absent'], Attendance::STATUSES['late'], Attendance::STATUSES['excused'], Attendance::STATUSES['medical'], 'Katılım (%)'],
            function (callable $add) use ($rows) {
                foreach ($rows as $r) {
                    $add([$r['student_no'], $r['full_name'], $r['class_names'] ?? '', $r['records'], $r['present'], $r['absent'], $r['late'], $r['excused'], $r['medical'], $r['rate'] ?? '']);
                }
            });
    }

    public function attendancePdf(Request $request, FinanceDocuments $documents): Response
    {
        $f = $this->attendanceFilters($request);
        $data = $this->reports->attendance($f);
        $students = $this->reports->attendanceStudents($f)->filter(fn ($r) => $r['absent'] + $r['late'] + $r['excused'] + $r['medical'] > 0)->values();
        $class = $f['class_group_id'] ? ClassGroup::query()->find($f['class_group_id'])?->name : null;
        Audit::log('report.attendance_exported', sprintf('%s – %s devamsızlık raporunu PDF olarak dışa aktardı.', $this->d($f['from']), $this->d($f['to'])));

        $pdf = Pdf::loadView('pdf.reports.attendance', ['r' => $data, 'students' => $students, 'className' => $class, 'institution' => $documents->institution()])
            ->setPaper('a4', 'portrait');

        return $pdf->download("devamsizlik-raporu-{$f['from']}-{$f['to']}.pdf");
    }

    /* ---------------------------------------------------------------- Sınavlar */

    public function exams(Request $request): JsonResponse
    {
        $request->validate(['exam_id' => ['nullable', 'integer'], 'class_group_id' => ['nullable', 'integer']]);
        $options = $this->reports->examOptions();
        $examId = $request->integer('exam_id') ?: ($options->first(fn ($e) => $e['participants'] > 0)['id'] ?? $options->first()['id'] ?? null);
        $classId = $request->integer('class_group_id') ?: null;
        $detail = $examId ? $this->reports->exam($examId, $classId) : null;

        return response()->json(['data' => [
            'exams' => $options,
            'selected_exam_id' => $detail ? $examId : null,
            'report' => $detail ? $detail + ['rows' => $this->reports->examRows($examId, $classId)] : null,
        ]]);
    }

    public function examsExport(Request $request): StreamedResponse
    {
        $request->validate(['exam_id' => ['required', 'integer'], 'class_group_id' => ['nullable', 'integer']]);
        $examId = $request->integer('exam_id');
        $classId = $request->integer('class_group_id') ?: null;
        $detail = $this->reports->exam($examId, $classId);
        abort_if($detail === null, 404);
        $rows = $this->reports->examRows($examId, $classId);
        Audit::log('report.exam_exported', "{$detail['exam']['name']} sınav sonuç özetini Excel olarak dışa aktardı.");

        return $this->xlsx('sinav-ozeti-'.$examId.'.xlsx',
            ['Sıra', 'Öğrenci No', 'Ad Soyad', 'Sınıf', 'Doğru', 'Yanlış', 'Boş', 'Net', 'Puan', 'Kurum sırası', 'Sınıf sırası'],
            function (callable $add) use ($rows, $detail) {
                foreach ($rows as $i => $r) {
                    $add([$i + 1, $r['student_no'], $r['full_name'], $r['class_name'] ?? '', $r['correct'], $r['wrong'], $r['blank'], $r['net'] ?? '', $r['score'] ?? '', $r['institution_rank'] ?? '', $r['class_rank'] ?? '']);
                }
                $add([]);
                $add(['Sınıf özeti', '', 'Sınıf', 'Katılım', 'Ort. net', 'Ort. puan', 'En yüksek net']);
                foreach ($detail['by_class'] as $c) {
                    $add(['', '', $c['name'], $c['participants'], $c['avg_net'] ?? '', $c['avg_score'] ?? '', $c['max_net'] ?? '']);
                }
            });
    }

    /* ---------------------------------------------------------------- Ön kayıt */

    private function leadFilters(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', Rule::in(array_keys(Lead::SOURCES))],
        ]);

        return ['from' => $request->query('from') ?: null, 'to' => $request->query('to') ?: null, 'source' => $request->query('source') ?: null];
    }

    public function leads(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->leads($this->leadFilters($request))]);
    }

    public function leadsExport(Request $request): StreamedResponse
    {
        $r = $this->reports->leads($this->leadFilters($request));
        Audit::log('report.leads_exported', 'Ön kayıt dönüşüm raporunu Excel olarak dışa aktardı.');

        return $this->xlsx('on-kayit-raporu-'.now()->format('Y-m-d').'.xlsx', ['Bölüm', 'Ad', 'Aday', 'Kayda dönen', 'Dönüşüm (%)'], function (callable $add) use ($r) {
            $add(['Toplam', '', $r['summary']['total'], $r['summary']['won'], $r['summary']['rate'] ?? '']);
            foreach ($r['by_source'] as $s) {
                $add(['Kaynak', $s['label'], $s['total'], $s['won'], $s['rate'] ?? '']);
            }
            foreach ($r['monthly_trend'] as $m) {
                $total = (int) $m->total;
                $add(['Ay', $m->ym, $total, (int) $m->won, $total > 0 ? round((int) $m->won * 100 / $total, 1) : '']);
            }
            foreach ($r['by_source_month'] as $x) {
                $add(['Ay ve kaynak', $x['ym'].' · '.$x['label'], $x['total'], $x['won'], $x['total'] > 0 ? round($x['won'] * 100 / $x['total'], 1) : '']);
            }
            foreach ($r['by_owner'] as $o) {
                $add(['Sorumlu', $o['name'], $o['total'], $o['won'], $o['rate'] ?? '']);
            }
            foreach ($r['funnel'] as $f) {
                $add(['Aşama', $f['label'], $f['count'], '', '']);
            }
        });
    }

    /* ---------------------------------------------------------------- Öğretmen ders yükü */

    private function loadFilters(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']],
            ['to.after_or_equal' => 'Bitiş tarihi başlangıçtan önce olamaz.']);
        $today = CarbonImmutable::today();

        return ['from' => $request->query('from') ?: $today->startOfMonth()->toDateString(), 'to' => $request->query('to') ?: $today->endOfMonth()->toDateString()];
    }

    public function teacherLoad(Request $request): JsonResponse
    {
        $f = $this->loadFilters($request);

        return response()->json(['data' => ['from' => $f['from'], 'to' => $f['to'], 'rows' => $this->reports->teacherLoad($f)]]);
    }

    public function teacherLoadExport(Request $request): StreamedResponse
    {
        $f = $this->loadFilters($request);
        $rows = $this->reports->teacherLoad($f);
        Audit::log('report.teacher_load_exported', sprintf('%s – %s öğretmen ders yükü raporunu Excel olarak dışa aktardı.', $this->d($f['from']), $this->d($f['to'])));

        return $this->xlsx("ogretmen-ders-yuku-{$f['from']}-{$f['to']}.xlsx",
            ['Öğretmen', 'Branş', 'Durum', 'Haftalık program (saat)', 'Üst sınır (saat)', 'Hedef (saat)', 'Sınıf', 'Dönemdeki ders', 'İptal', 'Ders saati', 'Yoklama alınan', 'Yoklaması eksik', 'Etüt/birebir', 'Etüt saati', 'İzin (gün)'],
            function (callable $add) use ($rows) {
                foreach ($rows as $r) {
                    $add([$r['name'], $r['specialty'] ?? '', $r['is_active'] ? 'Aktif' : 'Pasif', $r['weekly_hours'], $r['max_weekly_hours'] ?? '', $r['target_weekly_hours'] ?? '',
                        $r['classes'], $r['sessions'], $r['cancelled'], $r['lesson_hours'], $r['attendance_taken'], $r['attendance_missing'], $r['studies'], $r['study_hours'], $r['leave_days']]);
                }
            });
    }

    /* ---------------------------------------------------------------- Yardımcılar */

    private function d(string $iso): string
    {
        return CarbonImmutable::parse($iso)->format('d.m.Y');
    }

    /**
     * @param list<string> $header
     * @param callable(callable(array): void): void $rows
     */
    private function xlsx(string $filename, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($header));
            $rows(fn (array $values) => $writer->addRow(Row::fromValues(array_values($values))));
            $writer->close();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
