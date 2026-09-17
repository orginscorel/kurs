<?php

namespace App\Http\Controllers\Api\Exams;

use App\Http\Controllers\Api\ApiController;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Student;
use App\Services\Exams\ExamAnalytics;
use App\Services\Exams\ExamResultService;
use App\Services\Exams\Optical\OpticalImportService;
use App\Services\Exams\ReportCardRenderer;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExamResultController extends ApiController
{
    use Concerns\TurkishValidation;

    public function index(Request $request, Exam $exam): JsonResponse
    {
        $exam->load('sections');
        $query = $this->filtered($request, $exam);
        $this->applySort($query, $request, [
            'net' => 'exam_results.net', 'score' => 'exam_results.score', 'institution_rank' => 'exam_results.institution_rank', 'class_rank' => 'exam_results.class_rank',
            'national_rank' => 'exam_results.national_rank', 'name' => 'students.full_name', 'correct' => 'exam_results.correct', 'wrong' => 'exam_results.wrong', 'blank' => 'exam_results.blank',
        ], 'institution_rank');
        $query->orderBy('exam_results.id');

        $paginator = $query->paginate($this->perPage($request, 50));
        $sectionCodes = $exam->sections->pluck('code', 'id');

        $summary = $this->filtered($request, $exam)->toBase()->select(DB::raw('COUNT(*) AS participants, AVG(exam_results.net) AS avg_net, MAX(exam_results.net) AS max_net, AVG(exam_results.score) AS avg_score'))->first();

        return $this->paginated($paginator, fn (ExamResult $r) => [
            'id' => $r->id, 'student_id' => $r->student_id, 'student_no' => $r->student->student_no, 'student_name' => $r->student->full_name,
            'class_group' => $r->classGroup?->name, 'class_group_id' => $r->class_group_id, 'booklet' => $r->booklet,
            'correct' => $r->correct, 'wrong' => $r->wrong, 'blank' => $r->blank, 'net' => (float) $r->net, 'score' => $r->score !== null ? (float) $r->score : null,
            'institution_rank' => $r->institution_rank, 'class_rank' => $r->class_rank, 'national_rank' => $r->national_rank, 'source' => $r->source,
            'sections' => $r->sections->mapWithKeys(fn ($s) => [$sectionCodes[$s->exam_section_id] ?? $s->exam_section_id => ['net' => (float) $s->net, 'correct' => $s->correct, 'wrong' => $s->wrong, 'blank' => $s->blank]]),
        ], [
            'sections' => $exam->sections->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'question_count' => (int) $s->question_count]),
            'summary' => ['participants' => (int) $summary->participants, 'avg_net' => round((float) $summary->avg_net, 2), 'max_net' => round((float) $summary->max_net, 2), 'avg_score' => $summary->avg_score !== null ? round((float) $summary->avg_score, 2) : null],
            'class_groups' => DB::table('exam_results as r')->join('class_groups as g', 'g.id', '=', 'r.class_group_id')->where('r.exam_id', $exam->id)->groupBy('g.id', 'g.name')->orderBy('g.name')->get(['g.id', 'g.name', DB::raw('COUNT(*) AS n')]),
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'status' => $exam->status, 'exam_date' => $exam->exam_date->toDateString(), 'booklets' => $exam->booklets, 'scope' => $exam->scope],
        ]);
    }

    public function export(Request $request, Exam $exam): StreamedResponse
    {
        $exam->load('sections');
        $query = $this->filtered($request, $exam)->orderBy('exam_results.institution_rank')->orderBy('exam_results.id');
        $sections = $exam->sections;
        Audit::log('exam.results_exported', "{$exam->name} sonuçlarını Excel olarak dışa aktardı.", $exam);

        return response()->streamDownload(function () use ($query, $sections) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $header = ['Kurum Sırası', 'Sınıf Sırası', 'Genel Sıra', 'Öğrenci No', 'Ad Soyad', 'Sınıf', 'Kitapçık'];
            foreach ($sections as $s) {
                $header[] = "{$s->code} D";
                $header[] = "{$s->code} Y";
                $header[] = "{$s->code} B";
                $header[] = "{$s->code} Net";
            }
            array_push($header, 'Doğru', 'Yanlış', 'Boş', 'Toplam Net', 'Puan');
            $writer->addRow(Row::fromValues($header));

            $query->chunk(300, function ($chunk) use ($writer, $sections) {
                foreach ($chunk as $r) {
                    $row = [$r->institution_rank, $r->class_rank, $r->national_rank, $r->student->student_no, $r->student->full_name, $r->classGroup?->name ?? '', $r->booklet];
                    foreach ($sections as $s) {
                        $rs = $r->sections->firstWhere('exam_section_id', $s->id);
                        array_push($row, $rs?->correct ?? 0, $rs?->wrong ?? 0, $rs?->blank ?? 0, (float) ($rs?->net ?? 0));
                    }
                    array_push($row, $r->correct, $r->wrong, $r->blank, (float) $r->net, $r->score !== null ? (float) $r->score : '');
                    $writer->addRow(Row::fromValues($row));
                }
            });
            $writer->close();
        }, 'sonuclar-'.\Illuminate\Support\Str::slug($exam->name).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Tek sonuç: cevap-anahtar karşılaştırması, konu bazlı özet ve net geçmişi. */
    public function show(Exam $exam, ExamResult $result, ExamAnalytics $analytics, ReportCardRenderer $renderer): JsonResponse
    {
        abort_unless($result->exam_id === $exam->id, 404);
        $exam->load('sections.questions.topic:id,name');
        $result->load(['student:id,student_no,full_name,photo_path', 'classGroup:id,name', 'sections']);

        $answers = $this->answerBreakdown($exam, $result);
        $topicAgg = [];
        foreach ($answers as $sec) {
            foreach ($sec['questions'] as $q) {
                if (! $q['topic']) {
                    continue;
                }
                $t = &$topicAgg[$q['topic']['id']];
                $t ??= ['id' => $q['topic']['id'], 'name' => $q['topic']['name'], 'section' => $sec['name'], 'asked' => 0, 'correct' => 0, 'wrong' => 0, 'blank' => 0];
                $t['asked']++;
                $t[$q['state'] === 'c' || $q['state'] === 'x' ? 'correct' : ($q['state'] === 'w' ? 'wrong' : 'blank')]++;
                unset($t);
            }
        }
        $topics = collect($topicAgg)->map(fn ($t) => $t + ['rate' => round($t['correct'] / max(1, $t['asked']) * 100)])->sortBy('rate')->values();

        return response()->json([
            'result' => [
                'id' => $result->id, 'student' => ['id' => $result->student->id, 'no' => $result->student->student_no, 'name' => $result->student->full_name],
                'class_group' => $result->classGroup?->name, 'booklet' => $result->booklet, 'source' => $result->source,
                'correct' => $result->correct, 'wrong' => $result->wrong, 'blank' => $result->blank, 'net' => (float) $result->net, 'score' => $result->score !== null ? (float) $result->score : null,
                'institution_rank' => $result->institution_rank, 'class_rank' => $result->class_rank, 'national_rank' => $result->national_rank,
                'participants' => (int) $exam->participant_count ?: $exam->results()->count(),
                'class_total' => $result->class_group_id ? ExamResult::query()->where('exam_id', $exam->id)->where('class_group_id', $result->class_group_id)->count() : null,
                'updated_at' => $result->updated_at?->toIso8601String(),
            ],
            'sections' => $answers,
            'topics' => ['weak' => $topics->filter(fn ($t) => $t['rate'] < 50)->take(6)->values(), 'strong' => $topics->sortByDesc('rate')->filter(fn ($t) => $t['rate'] >= 70)->take(6)->values()],
            'history' => $analytics->studentHistory($result->student_id, $exam->exam_type_id, 10)->map(fn ($h) => ['id' => $h->id, 'name' => $h->name, 'exam_date' => $h->exam_date, 'net' => (float) $h->net, 'score' => $h->score !== null ? (float) $h->score : null, 'institution_rank' => $h->institution_rank]),
        ]);
    }

    /** Elle sonuç girişi (tek öğrenci). */
    public function manual(Request $request, Exam $exam, OpticalImportService $optical): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')],
            'booklet' => ['nullable', 'string', 'max:2'],
            'answers' => ['required', 'array'], 'answers.*' => ['nullable', 'string', 'max:200'],
        ], $this->messages(), $this->attributes());
        $student = Student::query()->findOrFail($data['student_id']);
        $optical->manualEntry($exam, $student, strtoupper($data['booklet'] ?? 'A'), $data['answers']);

        return $this->ok("{$student->full_name} sonucu kaydedildi.");
    }

    public function destroy(Exam $exam, ExamResult $result, ExamResultService $results): JsonResponse
    {
        abort_unless($result->exam_id === $exam->id, 404);
        $studentId = $result->student_id;
        $name = $result->student?->full_name;
        DB::transaction(function () use ($result, $exam, $results, $studentId) {
            $result->delete();
            $results->refreshAnalytics($exam);
            $results->rebuildStudentTopicStats([$studentId]);
        });
        Audit::log('exam.result_deleted', "{$exam->name} sınavından {$name} sonucunu sildi.", $exam);

        return $this->ok('Sonuç silindi; sıralamalar güncellendi.');
    }

    /** Öğrenci sonuç belgesi PDF (dompdf, DejaVu Sans). */
    public function pdf(Exam $exam, ExamResult $result, ReportCardRenderer $renderer): Response
    {
        abort_unless($result->exam_id === $exam->id, 404);
        $exam->load('sections.questions.topic:id,name');
        $result->load('sections');
        $data = $renderer->data($result);
        $answers = $this->answerBreakdown($exam, $result);

        // Ders bazında kurum ve sınıf ortalama netleri (optik sonuç belgesindeki karşılaştırma sütunları)
        $ortalama = fn (?int $sinif) => DB::table('exam_result_sections as rs')
            ->join('exam_results as r', 'r.id', '=', 'rs.exam_result_id')
            ->where('r.exam_id', $exam->id)
            ->when($sinif, fn ($q) => $q->where('r.class_group_id', $sinif))
            ->groupBy('rs.exam_section_id')
            ->selectRaw('rs.exam_section_id as bolum, AVG(rs.net) as ortalama')
            ->pluck('ortalama', 'bolum')
            ->map(fn ($v) => round((float) $v, 2))->all();
        $genel = fn (?int $sinif) => round((float) DB::table('exam_results')->where('exam_id', $exam->id)
            ->when($sinif, fn ($q) => $q->where('class_group_id', $sinif))->avg('net'), 2);
        $ort = [
            'kurum' => $ortalama(null),
            'sinif' => $result->class_group_id ? $ortalama($result->class_group_id) : [],
            'kurum_net' => $genel(null),
            'sinif_net' => $result->class_group_id ? $genel($result->class_group_id) : null,
        ];

        // Öğrencinin konu (kazanım) analizi
        $konular = [];
        foreach ($answers as $sec) {
            foreach ($sec['questions'] as $q) {
                if (! $q['topic'] || $q['state'] === 'x') {
                    continue;
                }
                $k = $sec['id'].'|'.$q['topic']['id'];
                $konular[$k] ??= ['ders' => $sec['name'], 'konu' => $q['topic']['name'], 'soru' => 0, 'd' => 0, 'y' => 0, 'b' => 0];
                $konular[$k]['soru']++;
                $konular[$k][$q['state'] === 'c' ? 'd' : ($q['state'] === 'w' ? 'y' : 'b')]++;
            }
        }

        $pdf = Pdf::loadView('exams.result-card', ['d' => $data, 'answers' => $answers, 'ort' => $ort, 'konular' => array_values($konular)])->setPaper('a4');
        $file = 'sonuc-'.\Illuminate\Support\Str::slug($data['student']['name']).'-'.\Illuminate\Support\Str::slug($exam->name).'.pdf';

        return $pdf->download($file);
    }

    /** WhatsApp için markalı PNG sonuç kartı. */
    public function card(Request $request, Exam $exam, ExamResult $result, ReportCardRenderer $renderer): BinaryFileResponse
    {
        abort_unless($result->exam_id === $exam->id, 404);
        $path = $renderer->render($result, fresh: $request->boolean('fresh'));
        $name = 'sonuc-karti-'.\Illuminate\Support\Str::slug($result->student->full_name).'.png';

        return response()->file($path, ['Content-Type' => 'image/png', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"', 'Cache-Control' => 'private, max-age=60']);
    }

    // ------------------------------------------------------------------ yardımcılar

    private function filtered(Request $request, Exam $exam): Builder
    {
        $query = ExamResult::query()->with(['student:id,student_no,full_name', 'classGroup:id,name', 'sections'])
            ->join('students', 'students.id', '=', 'exam_results.student_id')->select('exam_results.*')
            ->where('exam_results.exam_id', $exam->id);
        if ($g = $request->integer('class_group_id')) {
            $query->where('exam_results.class_group_id', $g);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn ($w) => $w->where('students.full_name', 'like', "%{$q}%")->orWhere('students.student_no', 'like', "{$q}%"));
        }
        if ($b = $request->query('booklet')) {
            $query->where('exam_results.booklet', strtoupper($b));
        }

        return $query;
    }

    /** @return list<array{code:string,name:string,net:float,questions:list<array{number:int,given:string,key:string,state:string,topic:?array}>}> */
    private function answerBreakdown(Exam $exam, ExamResult $result): array
    {
        $out = [];
        foreach ($exam->sections as $section) {
            $rs = $result->sections->firstWhere('exam_section_id', $section->id);
            $answers = $rs?->answers ?? '';
            $questions = [];
            foreach ($section->questions as $i => $q) {
                $given = $answers[$i] ?? ' ';
                $key = strtoupper($q->booklet_map['A']['answer'] ?? '');
                $state = $q->is_cancelled ? 'x' : ($given === ' ' ? 'b' : ($given === $key ? 'c' : 'w'));
                $questions[] = ['id' => $q->id, 'number' => $q->number, 'given' => $given, 'key' => $key, 'state' => $state, 'booklet_no' => (int) ($q->booklet_map[$result->booklet]['no'] ?? $q->number), 'topic' => $q->topic ? ['id' => $q->topic->id, 'name' => $q->topic->name] : null];
            }
            $out[] = ['id' => $section->id, 'code' => $section->code, 'name' => $section->name, 'net' => (float) ($rs?->net ?? 0), 'correct' => $rs?->correct ?? 0, 'wrong' => $rs?->wrong ?? 0, 'blank' => $rs?->blank ?? 0, 'questions' => $questions];
        }

        return $out;
    }
}
