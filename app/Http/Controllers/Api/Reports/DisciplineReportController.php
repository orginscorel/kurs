<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Api\ApiController;
use App\Models\ClassGroup;
use App\Models\DisciplineBehavior;
use App\Models\Teacher;
use App\Services\Discipline\DisciplinePdf;
use App\Services\Discipline\DisciplineStanding;
use App\Services\Reports\DisciplineReportService;
use App\Support\Audit;
use App\Support\Discipline\DisciplineCatalog as C;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Rapor merkezi › Disiplin (reports.view + discipline.view; dışa aktarma + reports.export + discipline.export). */
class DisciplineReportController extends ApiController
{
    public function __construct(private readonly DisciplineReportService $reports) {}

    private function filters(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'class_group_id' => ['nullable', 'integer'], 'behavior_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(array_keys(C::CATEGORIES))],
        ], ['to.after_or_equal' => 'Bitiş tarihi başlangıçtan önce olamaz.']);
        $term = DisciplineStanding::termRange();
        $from = $request->query('from') ?: $term['from'];
        $to = $request->query('to') ?: min($term['to'], CarbonImmutable::today()->toDateString());
        if ($to < $from) {
            $to = $from;
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 800) {
            abort(response()->json(['message' => 'Tarih aralığı en fazla 2 yıl olabilir.', 'errors' => ['from' => ['Tarih aralığı en fazla 2 yıl olabilir.']]], 422));
        }

        return [
            'from' => $from, 'to' => $to,
            'class_group_id' => $request->integer('class_group_id') ?: null, 'behavior_id' => $request->integer('behavior_id') ?: null,
            'teacher_id' => $request->integer('teacher_id') ?: null, 'category' => $request->query('category') ?: null,
        ];
    }

    public function show(Request $request): JsonResponse
    {
        $f = $this->filters($request);

        return response()->json(['data' => $this->reports->report($f), 'filters' => [
            'term' => DisciplineStanding::termRange(),
            'class_groups' => ClassGroup::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name', 'is_active']),
            'behaviors' => DisciplineBehavior::query()->orderBy('kind')->orderBy('sort_order')->get(['id', 'name', 'kind']),
            'teachers' => Teacher::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->full_name]),
            'categories' => C::CATEGORIES,
        ]]);
    }

    private function scope(array $f): string
    {
        $parts = [];
        if ($f['class_group_id']) {
            $parts[] = ClassGroup::query()->whereKey($f['class_group_id'])->value('name');
        }
        if ($f['behavior_id']) {
            $parts[] = DisciplineBehavior::query()->whereKey($f['behavior_id'])->value('name');
        }
        if ($f['category']) {
            $parts[] = C::CATEGORIES[$f['category']];
        }
        if ($f['teacher_id']) {
            $parts[] = Teacher::query()->find($f['teacher_id'])?->full_name;
        }

        return implode(' · ', array_filter($parts)) ?: 'Tüm kurum';
    }

    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $r = $this->reports->report($f);
        $incidents = $this->reports->incidentRows($f);
        $sanctions = $this->reports->sanctionRows($f);
        $d = fn ($v) => $v ? CarbonImmutable::parse($v)->format('d.m.Y') : '';
        Audit::log('report.discipline_exported', sprintf('%s – %s disiplin raporunu Excel olarak dışa aktardı.', $d($f['from']), $d($f['to'])));

        return response()->streamDownload(function () use ($r, $incidents, $sanctions, $d, $f) {
            $w = new Writer();
            $w->openToFile('php://output');
            $add = fn (array $v) => $w->addRow(Row::fromValues(array_values($v)));
            $sheet = $w->getCurrentSheet();
            $sheet->setName('Özet');
            $t = $r['totals'];
            $add(['Disiplin raporu', $d($f['from']).' – '.$d($f['to']), $this->scope($f)]);
            $add([]);
            foreach ([['Olay', $t['incidents']], ['Olaya karışan öğrenci', $t['students']], ['Ceza puanı', $t['penalty']], ['Olumlu kayıt', $t['positives']],
                ['Olumlu puan', $t['merit']], ['Yaptırım', $t['sanctions']], ['Yürürlükte yaptırım', $t['active_sanctions']], ['Uzaklaştırma günü', $t['suspension_days']],
                ['Açık / incelemede olay', $t['open_incidents']], ['Tekrar eden öğrenci', $t['repeaters']]] as $x) {
                $add($x);
            }
            $add([]);
            $add(['Davranış', 'Kategori', 'Tür', 'Sayı', 'Puan']);
            foreach ($r['by_behavior'] as $b) {
                $add([$b['name'], $b['category_label'], $b['kind'] === 'positive' ? 'Olumlu' : 'Olumsuz', $b['count'], $b['points']]);
            }
            $add([]);
            $add(['Yaptırım', 'Sayı', 'Yürürlükte']);
            foreach ($r['by_sanction'] as $s) {
                $add([$s['name'], $s['count'], $s['active']]);
            }
            $add([]);
            $add(['Sınıf', 'Olay', 'Öğrenci', 'Ceza puanı', 'Yaptırım', 'Olumlu']);
            foreach ($r['by_class'] as $c) {
                $add([$c['name'], $c['incidents'], $c['students'], $c['penalty'], $c['sanctions'], $c['positives']]);
            }
            $add([]);
            $add(['Bildiren', 'Olay', 'Olumlu kayıt']);
            foreach ($r['by_teacher'] as $x) {
                $add([$x['name'], $x['incidents'], $x['positives']]);
            }
            $add([]);
            $add(['Ay', 'Olay', 'Olumlu', 'Yaptırım']);
            foreach ($r['trend'] as $m) {
                $add([$m['label'], $m['incidents'], $m['positives'], $m['sanctions']]);
            }

            $w->addNewSheetAndMakeItCurrent()->setName('Tekrar edenler');
            $add(['Öğrenci No', 'Ad Soyad', 'Sınıf', 'Olay', 'Ceza puanı', 'Olumlu puan', 'Net', 'Seviye', 'Yaptırım', 'Son olay']);
            foreach ($r['repeaters'] as $s) {
                $add([$s['student_no'], $s['full_name'], $s['class_names'], $s['incidents'], $s['penalty'], $s['merit'], $s['net'], $s['level_label'], $s['sanctions'], $d($s['last_at'])]);
            }

            $w->addNewSheetAndMakeItCurrent()->setName('Olaylar');
            $add(['Olay No', 'Tarih', 'Tür', 'Durum', 'Ciddiyet', 'Yer', 'Öğrenci No', 'Ad Soyad', 'Davranış', 'Kategori', 'Ceza puanı', 'Olumlu puan', 'Kaydeden']);
            foreach ($incidents as $i) {
                $add([$i->incident_no, CarbonImmutable::parse($i->occurred_at)->format('d.m.Y H:i'), $i->kind === 'positive' ? 'Olumlu' : 'Olumsuz',
                    C::INCIDENT_STATUSES[$i->status] ?? $i->status, C::SEVERITIES[$i->severity] ?? $i->severity, $i->location ?? '', $i->student_no, $i->full_name,
                    $i->behavior ?? '', C::CATEGORIES[$i->category] ?? '', (int) $i->penalty_points, (int) $i->merit_points, $i->reporter ?? '']);
            }

            $w->addNewSheetAndMakeItCurrent()->setName('Yaptırımlar');
            $add(['Yaptırım No', 'Olay No', 'Öğrenci No', 'Ad Soyad', 'Yaptırım', 'Durum', 'Karar', 'Başlangıç', 'Bitiş', 'Gün', 'Düşme']);
            foreach ($sanctions as $s) {
                $add([$s->sanction_no, $s->incident_no, $s->student_no, $s->full_name, $s->type, C::SANCTION_STATUSES[$s->status] ?? $s->status,
                    $d($s->decided_at), $d($s->starts_on), $d($s->ends_on), $s->days ?? '', $d($s->expires_on)]);
            }
            $w->close();
        }, "disiplin-raporu-{$f['from']}-{$f['to']}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdf(Request $request, DisciplinePdf $pdf): Response
    {
        $f = $this->filters($request);
        Audit::log('report.discipline_exported', sprintf('%s – %s disiplin raporunu PDF olarak dışa aktardı.', $f['from'], $f['to']));

        return $pdf->report($this->reports->report($f), $this->scope($f));
    }
}
