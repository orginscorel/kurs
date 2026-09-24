<?php

namespace App\Http\Controllers\Api\Students;

use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Services\Finance\FinanceDocuments;
use App\Support\BranchContext;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toplu öğrenci listesi çıktısı (sınıf → ad soyad sırasıyla).
 * - Liste: öğrenci no, sınıf, ad soyad, telefon, veli ad soyad, veli telefonu
 * - Yoklama çizelgesi: aynı liste + elle işaretlenecek boş gün/ders sütunları
 * PDF (A4) ve Excel olarak alınır; her sınıf isteğe bağlı olarak ayrı sayfada başlar.
 */
class StudentRosterController extends ApiController
{
    public const STATUSES = ['active' => 'Aktif', 'enrolled' => 'Kayıt tamamlandı', 'pending' => 'Kayıt bekliyor', 'frozen' => 'Donduruldu'];

    private const NO_CLASS = 'Sınıfsız';

    /** Çıktı penceresi için sınıf listesi ve sayımlar. */
    public function options(): JsonResponse
    {
        $term = AcademicTerm::current();
        $classes = $term ? $this->classes($term->id) : collect();
        $counts = $term ? DB::table('class_group_student as cgs')->join('students as s', 's.id', '=', 'cgs.student_id')
            ->where('s.branch_id', $this->branch())->whereIn('cgs.class_group_id', $classes->pluck('id'))->whereNull('cgs.left_on')->whereNull('s.deleted_at')
            ->whereIn('s.status', array_keys(self::STATUSES))
            ->groupBy('cgs.class_group_id')->selectRaw('cgs.class_group_id as id, COUNT(*) as c')->pluck('c', 'id') : collect();

        return response()->json(['data' => [
            'term' => $term?->name,
            'classes' => $classes->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'students' => (int) ($counts[$c->id] ?? 0)])->values(),
            'unassigned' => $this->rows($term?->id, [], true, array_keys(self::STATUSES), onlyUnassigned: true)->count(),
            'statuses' => self::STATUSES,
        ]]);
    }

    /** Seçimlere göre çıktının ilk satırları ve sınıf bazında sayılar (sayfadaki önizleme). */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'class_group_ids' => ['nullable', 'array'], 'class_group_ids.*' => ['integer'],
            'include_unassigned' => ['nullable', 'boolean'],
            'statuses' => ['nullable', 'array'], 'statuses.*' => [Rule::in(array_keys(self::STATUSES))],
        ]);
        $term = AcademicTerm::current();
        $rows = $this->rows($term?->id, $data['class_group_ids'] ?? [], (bool) ($data['include_unassigned'] ?? true), $data['statuses'] ?? ['active', 'enrolled', 'pending']);

        return response()->json(['data' => [
            'total' => $rows->count(),
            'groups' => $rows->groupBy('class_name')->map(fn ($g, $name) => ['name' => $name, 'count' => $g->count()])->values(),
            'sample' => $rows->take(6)->map(fn ($r) => collect($r)->only(['student_no', 'full_name', 'class_name', 'phone', 'guardian_name', 'relationship', 'guardian_phone']))->values(),
        ]]);
    }

    public function export(Request $request, FinanceDocuments $documents): Response
    {
        $data = $request->validate([
            'format' => ['nullable', Rule::in(['pdf', 'xlsx'])],
            'mode' => ['nullable', Rule::in(['list', 'attendance'])],
            'class_group_ids' => ['nullable', 'array'],
            'class_group_ids.*' => ['integer'],
            'include_unassigned' => ['nullable', 'boolean'],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::in(array_keys(self::STATUSES))],
            'columns' => ['nullable', 'integer', 'min:1', 'max:12'],
            'column_labels' => ['nullable', 'string', 'max:300'],
            'page_per_class' => ['nullable', 'boolean'],
            'title' => ['nullable', 'string', 'max:120'],
            'date' => ['nullable', 'date'],
        ]);
        if (($data['format'] ?? 'pdf') === 'xlsx' && ! $request->user()->can('students.export')) {
            abort(403, 'Excel dışa aktarma yetkiniz yok.');
        }

        $term = AcademicTerm::current();
        $mode = $data['mode'] ?? 'list';
        $statuses = $data['statuses'] ?? ['active', 'enrolled', 'pending'];
        $includeUnassigned = (bool) ($data['include_unassigned'] ?? true);
        $rows = $this->rows($term?->id, $data['class_group_ids'] ?? [], $includeUnassigned, $statuses);

        $groups = $rows->groupBy('class_name');
        $columns = $mode === 'attendance' ? $this->columnLabels($data['column_labels'] ?? null, (int) ($data['columns'] ?? 6)) : [];
        $title = trim((string) ($data['title'] ?? '')) ?: ($mode === 'attendance' ? 'Yoklama Çizelgesi' : 'Öğrenci Listesi');
        $date = CarbonImmutable::parse($data['date'] ?? now());
        $stamp = $date->format('Y-m-d');

        Audit::log('students.roster_exported', sprintf('%s çıktısı alındı (%s, %d öğrenci, %d sınıf).', $title, strtoupper($data['format'] ?? 'pdf'), $rows->count(), $groups->count()));

        if (($data['format'] ?? 'pdf') === 'xlsx') {
            $mark = $mode === 'attendance' ? 'İşaret / Mazeret' : 'İşaret';
            $header = ['Grup', 'Ad', 'Soyad', $mark];

            return response()->streamDownload(function () use ($groups, $header) {
                $writer = new Writer();
                $writer->openToFile('php://output');
                $writer->addRow(Row::fromValues($header));
                foreach ($groups as $list) {
                    foreach ($list->values() as $r) {
                        $writer->addRow(Row::fromValues([$r['class_name'], $r['first_name'], $r['last_name'], '']));
                    }
                }
                $writer->close();
            }, ($mode === 'attendance' ? 'yoklama-cizelgesi-' : 'ogrenci-listesi-')."{$stamp}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        $pdf = Pdf::loadView('pdf.students.roster', [
            'institution' => $documents->institution(),
            'groups' => $groups,
            'mode' => $mode,
            'columns' => $columns,
            'title' => $title,
            'date' => $date->format('d.m.Y'),
            'term' => $term?->name,
            'pagePerClass' => (bool) ($data['page_per_class'] ?? true),
            'total' => $rows->count(),
        ])->setPaper('a4', 'portrait');

        return $pdf->download(($mode === 'attendance' ? 'yoklama-cizelgesi-' : 'ogrenci-listesi-')."{$stamp}.pdf");
    }

    /** Güncel dönemin aktif sınıfları: seviye → şube → ad. */
    private function classes(int $termId)
    {
        return DB::table('class_groups')->where('branch_id', $this->branch())->where('academic_term_id', $termId)->where('is_active', true)->whereNull('deleted_at')
            ->get(['id', 'name', 'grade_level', 'section'])
            ->sort(fn ($a, $b) => [$a->grade_level ?? 99, $a->section ?? '', $a->name] <=> [$b->grade_level ?? 99, $b->section ?? '', $b->name])
            ->values();
    }

    /**
     * Satırlar: öğrenci başına tek satır (birden çok sınıfı varsa her sınıfın listesinde yer alır).
     * Veli: birincil veli, yoksa ilk bağlı veli.
     */
    private function rows(?int $termId, array $classIds, bool $includeUnassigned, array $statuses, bool $onlyUnassigned = false)
    {
        $classes = $termId ? $this->classes($termId) : collect();
        $order = $classes->pluck('id')->flip();
        $names = $classes->pluck('name', 'id');
        $all = $classes->pluck('id')->map(fn ($v) => (int) $v)->all();
        $selected = $classIds ? array_values(array_intersect(array_map('intval', $classIds), $all)) : $all;

        $students = DB::table('students')->where('branch_id', $this->branch())->whereNull('deleted_at')->whereIn('status', $statuses)
            ->get(['id', 'student_no', 'first_name', 'last_name', 'phone'])->keyBy('id');

        $memberships = DB::table('class_group_student')->whereIn('class_group_id', $classes->pluck('id'))->whereNull('left_on')
            ->whereIn('student_id', $students->keys())->get(['student_id', 'class_group_id'])->groupBy('student_id');

        $guardians = DB::table('guardian_student as gs')->join('guardians as g', 'g.id', '=', 'gs.guardian_id')
            ->whereNull('g.deleted_at')->whereIn('gs.student_id', $students->keys())
            ->orderByDesc('gs.is_primary')->orderBy('gs.id')
            ->get(['gs.student_id', 'gs.relationship', 'g.first_name', 'g.last_name', 'g.phone', 'g.whatsapp_phone'])
            ->groupBy('student_id')->map->first();

        $out = collect();
        foreach ($students as $s) {
            $mine = collect($memberships[$s->id] ?? [])->pluck('class_group_id')->map(fn ($v) => (int) $v)->sortBy(fn ($id) => $order[$id] ?? PHP_INT_MAX)->values();
            $targets = $mine->filter(fn ($id) => in_array($id, $selected, true))->values();
            if ($onlyUnassigned) {
                $targets = $mine->isEmpty() ? collect([null]) : collect();
            } elseif ($mine->isEmpty()) {
                $targets = $includeUnassigned ? collect([null]) : collect();
            }
            $g = $guardians[$s->id] ?? null;
            foreach ($targets as $cid) {
                $out->push([
                    'id' => $s->id,
                    'student_no' => $s->student_no,
                    'first_name' => $s->first_name,
                    'last_name' => $s->last_name,
                    'full_name' => trim($s->first_name.' '.$s->last_name),
                    'phone' => $this->phone($s->phone),
                    'class_name' => $cid ? $names[$cid] : self::NO_CLASS,
                    'class_order' => $cid ? $order[$cid] : PHP_INT_MAX,
                    'guardian_name' => $g ? trim($g->first_name.' '.$g->last_name) : '',
                    'relationship' => $g ? (self::RELATIONS[$g->relationship] ?? (string) $g->relationship) : '',
                    'guardian_phone' => $g ? $this->phone($g->phone ?: $g->whatsapp_phone) : '',
                ]);
            }
        }

        $collator = class_exists(\Collator::class) ? new \Collator('tr_TR') : null;
        $cmp = fn (string $a, string $b) => $collator ? $collator->compare($a, $b) : strcasecmp($a, $b);

        return $out->sort(function ($a, $b) use ($cmp) {
            return $a['class_order'] <=> $b['class_order']
                ?: $cmp($a['first_name'], $b['first_name'])
                ?: $cmp($a['last_name'], $b['last_name']);
        })->values();
    }

    private function branch(): int
    {
        return app(BranchContext::class)->require();
    }

    private const RELATIONS = ['mother' => 'Anne', 'father' => 'Baba', 'guardian' => 'Vasi', 'sibling' => 'Kardeş', 'grandparent' => 'Büyükanne/baba', 'relative' => 'Akraba', 'other' => 'Diğer'];

    /** 05321234567 → 0532 123 45 67 */
    private function phone(?string $p): string
    {
        $d = preg_replace('/\D+/', '', (string) $p);
        if (strlen($d) === 12 && str_starts_with($d, '90')) {
            $d = '0'.substr($d, 2);
        }
        if (strlen($d) === 10) {
            $d = '0'.$d;
        }

        return strlen($d) === 11 ? substr($d, 0, 4).' '.substr($d, 4, 3).' '.substr($d, 7, 2).' '.substr($d, 9, 2) : (string) $p;
    }

    /** "Pzt, Sal, Çar" gibi virgüllü başlıklar; boşsa 1..N */
    private function columnLabels(?string $labels, int $count): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) $labels)), fn ($v) => $v !== ''));

        return $list ? array_slice($list, 0, 12) : array_map(fn ($i) => (string) $i, range(1, $count));
    }
}
