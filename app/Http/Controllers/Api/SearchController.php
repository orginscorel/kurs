<?php

namespace App\Http\Controllers\Api;

use App\Models\ClassGroup;
use App\Models\Exam;
use App\Models\Guardian;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global arama (Ctrl/Cmd+K). Yetkiye göre kaynaklar taranır; her kaynaktan en fazla 6 sonuç.
 * 3+ karakterde FULLTEXT kelime öneki, daha kısada ad öneki kullanılır (tam tarama yok).
 */
class SearchController extends ApiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        $user = $request->user();

        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }

        $hits = collect();
        $digits = preg_replace('/\D/', '', $q);

        if ($user->can('students.view')) {
            $students = Student::query()
                ->with('currentClassGroups:id,name')
                ->where(function (Builder $w) use ($q, $digits) {
                    $this->nameMatch($w, 'full_name', $q);
                    if ($digits !== '' && strlen($digits) >= 3) {
                        $w->orWhere('student_no', $digits)->orWhere('phone', 'like', "%{$digits}")
                            ->orWhere('national_id_last4', substr($digits, -4));
                    }
                })
                ->orderByRaw("status = 'active' DESC")->orderBy('full_name')
                ->limit(6)->get(['id', 'full_name', 'student_no', 'status', 'phone']);

            $hits = $hits->concat($students->map(fn ($s) => [
                'type' => 'student', 'id' => $s->id, 'title' => $s->full_name,
                'subtitle' => trim('No '.$s->student_no.' · '.($s->currentClassGroups->pluck('name')->join(', ') ?: 'Sınıf atanmamış')),
                'url' => "/ogrenciler/{$s->id}",
                'badge' => $s->status !== 'active' ? (Student::STATUSES[$s->status] ?? $s->status) : null,
            ]));
        }

        if ($user->can('guardians.view')) {
            $guardians = Guardian::query()
                ->with('students:id,full_name')
                ->where(function (Builder $w) use ($q, $digits) {
                    $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$q.'%'])->orWhere('last_name', 'like', $q.'%');
                    if (strlen($digits) >= 4) {
                        $w->orWhere('phone', 'like', "%{$digits}");
                    }
                })
                ->limit(5)->get(['id', 'first_name', 'last_name']);

            $hits = $hits->concat($guardians->map(fn ($g) => [
                'type' => 'guardian', 'id' => $g->id, 'title' => $g->full_name,
                'subtitle' => 'Veli · '.$g->students->pluck('full_name')->join(', '), 'url' => "/veliler/{$g->id}",
            ]));
        }

        if ($user->can('teachers.view')) {
            $teachers = Teacher::query()
                ->where(fn (Builder $w) => $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$q.'%'])->orWhere('last_name', 'like', $q.'%'))
                ->limit(5)->get(['id', 'first_name', 'last_name', 'title']);

            $hits = $hits->concat($teachers->map(fn ($t) => [
                'type' => 'teacher', 'id' => $t->id, 'title' => $t->full_name, 'subtitle' => $t->title ?: 'Öğretmen', 'url' => "/ogretmenler/{$t->id}",
            ]));
        }

        if ($user->can('academic.view')) {
            $groups = ClassGroup::query()->with('program:id,name')->where('name', 'like', "%{$q}%")->limit(4)->get(['id', 'name', 'program_id']);
            $hits = $hits->concat($groups->map(fn ($g) => [
                'type' => 'class_group', 'id' => $g->id, 'title' => $g->name, 'subtitle' => $g->program?->name, 'url' => "/akademik/siniflar/{$g->id}",
            ]));
        }

        if ($user->can('exams.view')) {
            $exams = Exam::query()->where('name', 'like', "%{$q}%")->orderByDesc('exam_date')->limit(4)->get(['id', 'name', 'exam_date']);
            $hits = $hits->concat($exams->map(fn ($e) => [
                'type' => 'exam', 'id' => $e->id, 'title' => $e->name, 'subtitle' => $e->exam_date->format('d.m.Y'), 'url' => "/sinavlar/{$e->id}",
            ]));
        }

        if ($user->can('crm.view')) {
            $leads = Lead::query()
                ->whereNull('student_id')
                ->where(fn (Builder $w) => $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$q.'%'])
                    ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', "%{$digits}")))
                ->limit(4)->get(['id', 'first_name', 'last_name', 'stage']);
            $hits = $hits->concat($leads->map(fn ($l) => [
                'type' => 'lead', 'id' => $l->id, 'title' => $l->full_name, 'subtitle' => 'Aday · '.(Lead::STAGES[$l->stage] ?? $l->stage), 'url' => "/crm?aday={$l->id}",
            ]));
        }

        if ($user->can('finance.view') && preg_match('/^[A-Z]{2,4}-?\d/i', $q)) {
            $payments = Payment::query()->with('student:id,full_name')->where('receipt_no', 'like', strtoupper($q).'%')->limit(4)->get();
            $hits = $hits->concat($payments->map(fn ($p) => [
                'type' => 'payment', 'id' => $p->id, 'title' => $p->receipt_no,
                'subtitle' => $p->student?->full_name.' · '.Money::format($p->amount).' ₺', 'url' => "/finans/tahsilatlar?makbuz={$p->receipt_no}",
                'badge' => $p->voided_at ? 'İptal' : null,
            ]));
        }

        return response()->json(['data' => $hits->values()]);
    }

    /** "ahmet yıl" → her kelime bir kelimenin başıyla eşleşmeli */
    private function nameMatch(Builder $w, string $column, string $q): void
    {
        $words = array_values(array_filter(preg_split('/\s+/u', $q), fn ($x) => mb_strlen($x) > 0));

        if (count($words) > 0 && min(array_map('mb_strlen', $words)) >= 3) {
            $boolean = implode(' ', array_map(fn ($word) => '+'.preg_replace('/[+\-><()~*"@]/u', '', $word).'*', $words));
            $w->whereRaw("MATCH(full_name, school_name) AGAINST (? IN BOOLEAN MODE)", [$boolean]);

            return;
        }

        $w->where($column, 'like', $q.'%');
        foreach ($words as $word) {
            $w->orWhere($column, 'like', '% '.$word.'%');
        }
    }
}
