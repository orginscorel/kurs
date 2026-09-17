<?php

namespace App\Services\Exams;

use App\Models\Exam;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sınav analizleri (salt okunur): soru bazlı, konu/kazanım, kümülatif ve Akademik Komuta Merkezi.
 * Sınıf filtresi olduğunda önbellek tablosu yerine cevap dizilerinden canlı hesaplanır.
 */
class ExamAnalytics
{
    /** Sınav özeti: bölüm ortalamaları, dağılım, sınıf ortalamaları, içe aktarma özeti. */
    public function overview(Exam $exam): array
    {
        $exam->loadMissing('sections');
        $agg = DB::table('exam_results')->where('exam_id', $exam->id)
            ->selectRaw('COUNT(*) AS participants, AVG(net) AS avg_net, MAX(net) AS max_net, MIN(net) AS min_net, AVG(score) AS avg_score, MAX(score) AS max_score, AVG(correct) AS avg_correct, AVG(wrong) AS avg_wrong, AVG(blank) AS avg_blank')
            ->first();

        $sections = DB::table('exam_result_sections as rs')->join('exam_sections as s', 's.id', '=', 'rs.exam_section_id')
            ->where('s.exam_id', $exam->id)->groupBy('s.id', 's.code', 's.name', 's.question_count', 's.sort')->orderBy('s.sort')
            ->get(['s.id', 's.code', 's.name', 's.question_count', DB::raw('AVG(rs.net) AS avg_net'), DB::raw('MAX(rs.net) AS max_net'), DB::raw('AVG(rs.correct) AS avg_correct'), DB::raw('AVG(rs.wrong) AS avg_wrong'), DB::raw('AVG(rs.blank) AS avg_blank')])
            ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'question_count' => (int) $s->question_count, 'avg_net' => round((float) $s->avg_net, 2), 'max_net' => round((float) $s->max_net, 2), 'avg_correct' => round((float) $s->avg_correct, 1), 'avg_wrong' => round((float) $s->avg_wrong, 1), 'avg_blank' => round((float) $s->avg_blank, 1)]);

        $classes = DB::table('exam_results as r')->join('class_groups as g', 'g.id', '=', 'r.class_group_id')
            ->where('r.exam_id', $exam->id)->groupBy('g.id', 'g.name')->orderByDesc(DB::raw('AVG(r.net)'))
            ->get(['g.id', 'g.name', DB::raw('COUNT(*) AS participants'), DB::raw('AVG(r.net) AS avg_net'), DB::raw('MAX(r.net) AS max_net')])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'participants' => (int) $c->participants, 'avg_net' => round((float) $c->avg_net, 2), 'max_net' => round((float) $c->max_net, 2)]);

        // Net dağılımı (histogram, 10 aralık)
        $total = array_sum($exam->sections->pluck('question_count')->all()) ?: 1;
        $bins = array_fill(0, 10, 0);
        DB::table('exam_results')->where('exam_id', $exam->id)->pluck('net')->each(function ($net) use (&$bins, $total) {
            $i = (int) min(9, max(0, floor(max(0, (float) $net) / $total * 10)));
            $bins[$i]++;
        });
        $distribution = [];
        foreach ($bins as $i => $n) {
            $distribution[] = ['label' => round($i / 10 * $total).'–'.round(($i + 1) / 10 * $total), 'count' => $n];
        }

        // Aynı türdeki önceki sınavla karşılaştırma
        $previous = Exam::query()->where('exam_type_id', $exam->exam_type_id)->where('status', 'results_published')
            ->where('exam_date', '<', $exam->exam_date)->whereKeyNot($exam->id)->orderByDesc('exam_date')->first(['id', 'name', 'exam_date']);
        $prevAvg = $previous ? DB::table('exam_results')->where('exam_id', $previous->id)->avg('net') : null;

        return [
            'participants' => (int) $agg->participants,
            'avg_net' => round((float) $agg->avg_net, 2), 'max_net' => round((float) $agg->max_net, 2), 'min_net' => round((float) $agg->min_net, 2),
            'avg_score' => $agg->avg_score !== null ? round((float) $agg->avg_score, 2) : null, 'max_score' => $agg->max_score !== null ? round((float) $agg->max_score, 2) : null,
            'avg_correct' => round((float) $agg->avg_correct, 1), 'avg_wrong' => round((float) $agg->avg_wrong, 1), 'avg_blank' => round((float) $agg->avg_blank, 1),
            'sections' => $sections, 'classes' => $classes, 'distribution' => $distribution,
            'previous' => $previous ? ['id' => $previous->id, 'name' => $previous->name, 'exam_date' => $previous->exam_date->toDateString(), 'avg_net' => round((float) $prevAvg, 2)] : null,
        ];
    }

    /** Soru bazlı: doğru/yanlış/boş %, şık dağılımı, en çok seçilen yanlış şık, vurgular. */
    public function questionAnalysis(Exam $exam, ?int $classGroupId = null): array
    {
        $exam->loadMissing('sections.questions.topic');
        $resultIds = $this->resultIds($exam, $classGroupId);
        $participants = count($resultIds);
        $sections = [];
        $all = [];

        foreach ($exam->sections as $section) {
            $answers = $participants === 0 ? collect() : DB::table('exam_result_sections')->where('exam_section_id', $section->id)->whereIn('exam_result_id', $resultIds)->pluck('answers');
            $questions = [];
            $sectionCorrect = 0;
            foreach ($section->questions as $index => $q) {
                $key = strtoupper($q->booklet_map['A']['answer'] ?? '');
                $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
                $correct = $wrong = $blank = 0;
                foreach ($answers as $string) {
                    $given = $string[$index] ?? ' ';
                    if ($given === ' ') {
                        $blank++;
                        continue;
                    }
                    $dist[$given] = ($dist[$given] ?? 0) + 1;
                    ($q->is_cancelled || $given === $key) ? $correct++ : $wrong++;
                }
                $n = max(1, $correct + $wrong + $blank);
                $wrongChoices = array_diff_key($dist, [$key => true]);
                arsort($wrongChoices);
                $mostWrong = array_key_first(array_filter($wrongChoices));
                $row = [
                    'id' => $q->id, 'number' => $q->number, 'key' => $key, 'is_cancelled' => $q->is_cancelled,
                    'booklet_no' => collect($q->booklet_map)->map(fn ($m) => $m['no'])->all(),
                    'topic' => $q->topic ? ['id' => $q->topic->id, 'name' => $q->topic->name] : null,
                    'correct' => $correct, 'wrong' => $wrong, 'blank' => $blank,
                    'correct_pct' => round($correct / $n * 100), 'wrong_pct' => round($wrong / $n * 100), 'blank_pct' => round($blank / $n * 100),
                    'most_common_wrong' => $mostWrong, 'most_common_wrong_pct' => $mostWrong ? round($dist[$mostWrong] / $n * 100) : 0,
                    'distribution' => $dist,
                ];
                $questions[] = $row;
                $all[] = $row + ['section' => $section->name, 'section_code' => $section->code];
                $sectionCorrect += $correct;
            }
            $sections[] = [
                'id' => $section->id, 'code' => $section->code, 'name' => $section->name, 'question_count' => (int) $section->question_count,
                'success_pct' => $participants && $section->questions->count() ? round($sectionCorrect / ($participants * $section->questions->count()) * 100) : 0,
                'questions' => $questions,
            ];
        }

        $highlights = [];
        if ($participants > 0 && $all !== []) {
            $hard = collect($all)->where('is_cancelled', false)->sortByDesc('wrong_pct')->take(3);
            foreach ($hard as $q) {
                if ($q['wrong_pct'] >= 40) {
                    $highlights[] = ['tone' => 'danger', 'text' => "{$q['section']} {$q['number']}. soru öğrencilerin %{$q['wrong_pct']}'i tarafından yanlış cevaplandı".($q['most_common_wrong'] ? " (en çok {$q['most_common_wrong']} seçildi)." : '.')];
                }
            }
            $blankiest = collect($all)->sortByDesc('blank_pct')->first();
            if ($blankiest && $blankiest['blank_pct'] >= 40) {
                $highlights[] = ['tone' => 'warning', 'text' => "{$blankiest['section']} {$blankiest['number']}. soru öğrencilerin %{$blankiest['blank_pct']}'i tarafından boş bırakıldı."];
            }
            $easy = collect($all)->where('is_cancelled', false)->sortByDesc('correct_pct')->first();
            if ($easy) {
                $highlights[] = ['tone' => 'success', 'text' => "En kolay soru: {$easy['section']} {$easy['number']} (%{$easy['correct_pct']} doğru)."];
            }
            $cancelled = collect($all)->where('is_cancelled', true)->count();
            if ($cancelled) {
                $highlights[] = ['tone' => 'info', 'text' => "{$cancelled} soru iptal edildi; iptal sorular herkese doğru sayıldı."];
            }
        }

        return ['participants' => $participants, 'sections' => $sections, 'highlights' => $highlights];
    }

    /** Sınav bazında konu/kazanım analizi + sınıf karşılaştırması. */
    public function topicAnalysis(Exam $exam, ?int $classGroupId = null): array
    {
        $exam->loadMissing('sections.questions.topic.subject');
        $resultIds = $this->resultIds($exam, $classGroupId);
        $participants = count($resultIds);

        // sonuç → sınıf eşlemesi (sınıf karşılaştırması için)
        $classOf = $participants ? DB::table('exam_results')->whereIn('id', $resultIds)->pluck('class_group_id', 'id')->all() : [];
        $classNames = DB::table('class_groups')->whereIn('id', array_filter(array_unique($classOf)))->pluck('name', 'id')->all();

        $topics = [];   // topic_id => agg
        $byClass = [];  // class_id => topic_id => [asked, correct]

        foreach ($exam->sections as $section) {
            $rows = $participants === 0 ? collect() : DB::table('exam_result_sections')->where('exam_section_id', $section->id)->whereIn('exam_result_id', $resultIds)->get(['exam_result_id', 'answers']);
            foreach ($section->questions as $index => $q) {
                if (! $q->topic_id) {
                    continue;
                }
                $t = &$topics[$q->topic_id];
                $t ??= ['id' => $q->topic_id, 'name' => $q->topic?->name ?? '—', 'outcome_code' => $q->topic?->outcome_code, 'subject' => $q->topic?->subject?->name ?? $section->name, 'section_code' => $section->code, 'questions' => 0, 'asked' => 0, 'correct' => 0, 'wrong' => 0, 'blank' => 0, 'numbers' => []];
                $t['questions']++;
                $t['numbers'][] = $q->number;
                $key = strtoupper($q->booklet_map['A']['answer'] ?? '');
                foreach ($rows as $row) {
                    $given = $row->answers[$index] ?? ' ';
                    $t['asked']++;
                    $ok = $q->is_cancelled || ($given !== ' ' && $given === $key);
                    if ($ok) {
                        $t['correct']++;
                    } elseif ($given === ' ') {
                        $t['blank']++;
                    } else {
                        $t['wrong']++;
                    }
                    $cid = $classOf[$row->exam_result_id] ?? null;
                    if ($cid) {
                        $c = &$byClass[$cid][$q->topic_id];
                        $c ??= ['asked' => 0, 'correct' => 0];
                        $c['asked']++;
                        $ok && $c['correct']++;
                        unset($c);
                    }
                }
                unset($t);
            }
        }

        $list = collect($topics)->map(function ($t) {
            $t['rate'] = $t['asked'] ? round($t['correct'] / $t['asked'] * 100) : null;
            $t['numbers'] = implode(', ', $t['numbers']);

            return $t;
        })->sortBy([['rate', 'asc'], ['name', 'asc']])->values();

        $classes = collect($byClass)->map(function ($topicsOfClass, $cid) use ($classNames) {
            return [
                'id' => $cid, 'name' => $classNames[$cid] ?? '—',
                'rates' => collect($topicsOfClass)->map(fn ($c) => $c['asked'] ? round($c['correct'] / $c['asked'] * 100) : null)->all(),
            ];
        })->sortBy('name')->values();

        $withData = $list->filter(fn ($t) => $t['rate'] !== null);

        return [
            'participants' => $participants,
            'topics' => $list,
            'hardest' => $withData->take(5)->values(),
            'easiest' => $withData->sortByDesc('rate')->take(5)->values(),
            'classes' => $classes,
            'untagged_questions' => $exam->sections->sum(fn ($s) => $s->questions->whereNull('topic_id')->count()),
        ];
    }

    /**
     * Kümülatif konu analizi (student_topic_stats): kurum geneli, sınıf ve öğrenci.
     *
     * @param array{class_group_id?:?int, student_id?:?int, subject_id?:?int, min_asked?:int} $f
     */
    public function cumulativeTopics(array $f = []): array
    {
        $branchId = app(BranchContext::class)->id();
        $minAsked = max(1, (int) ($f['min_asked'] ?? 3));

        $base = DB::table('student_topic_stats as st')->join('topics as tp', 'tp.id', '=', 'st.topic_id')->join('subjects as sb', 'sb.id', '=', 'tp.subject_id')
            ->join('students as s', 's.id', '=', 'st.student_id')->whereNull('s.deleted_at')
            ->when($branchId, fn ($q) => $q->where('sb.branch_id', $branchId))
            ->when($f['subject_id'] ?? null, fn ($q, $id) => $q->where('sb.id', $id))
            ->when($f['class_group_id'] ?? null, fn ($q, $id) => $q->whereExists(fn ($e) => $e->from('class_group_student as cgs')->whereColumn('cgs.student_id', 'st.student_id')->where('cgs.class_group_id', $id)->whereNull('cgs.left_on')))
            ->when($f['student_id'] ?? null, fn ($q, $id) => $q->where('st.student_id', $id));

        $topics = (clone $base)->groupBy('tp.id', 'tp.name', 'tp.outcome_code', 'sb.id', 'sb.name')
            ->get(['tp.id', 'tp.name', 'tp.outcome_code', 'sb.id as subject_id', 'sb.name as subject', DB::raw('COUNT(DISTINCT st.student_id) AS students'), DB::raw('SUM(st.asked) AS asked'), DB::raw('SUM(st.correct) AS correct'), DB::raw('SUM(st.wrong) AS wrong'), DB::raw('MAX(st.last_exam_at) AS last_exam_at')])
            ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'outcome_code' => $t->outcome_code, 'subject_id' => $t->subject_id, 'subject' => $t->subject, 'students' => (int) $t->students, 'asked' => (int) $t->asked, 'correct' => (int) $t->correct, 'wrong' => (int) $t->wrong, 'blank' => (int) $t->asked - (int) $t->correct - (int) $t->wrong, 'rate' => $t->asked ? round($t->correct / $t->asked * 100) : null, 'last_exam_at' => $t->last_exam_at])
            ->filter(fn ($t) => $t['asked'] >= $minAsked)->sortBy([['rate', 'asc'], ['name', 'asc']])->values();

        $subjects = $topics->groupBy('subject_id')->map(fn ($g) => ['id' => $g->first()['subject_id'], 'name' => $g->first()['subject'], 'topics' => $g->count(), 'asked' => $g->sum('asked'), 'correct' => $g->sum('correct'), 'rate' => $g->sum('asked') ? round($g->sum('correct') / $g->sum('asked') * 100) : null])->sortBy('name')->values();

        // Sınıf karşılaştırması: sınıf × ders başarı oranı (öğrenci filtresi yokken)
        $classes = [];
        if (empty($f['student_id'])) {
            $classes = DB::table('student_topic_stats as st')->join('topics as tp', 'tp.id', '=', 'st.topic_id')->join('subjects as sb', 'sb.id', '=', 'tp.subject_id')
                ->join('class_group_student as cgs', fn ($j) => $j->on('cgs.student_id', '=', 'st.student_id')->whereNull('cgs.left_on'))
                ->join('class_groups as g', 'g.id', '=', 'cgs.class_group_id')->whereNull('g.deleted_at')
                ->when($branchId, fn ($q) => $q->where('g.branch_id', $branchId))
                ->when($f['subject_id'] ?? null, fn ($q, $id) => $q->where('sb.id', $id))
                ->when($f['class_group_id'] ?? null, fn ($q, $id) => $q->where('g.id', $id))
                ->groupBy('g.id', 'g.name', 'sb.id', 'sb.name')
                ->get(['g.id as class_id', 'g.name as class_name', 'sb.id as subject_id', 'sb.name as subject', DB::raw('SUM(st.asked) AS asked'), DB::raw('SUM(st.correct) AS correct'), DB::raw('COUNT(DISTINCT st.student_id) AS students')])
                ->groupBy('class_id')->map(fn ($g) => [
                    'id' => $g->first()->class_id, 'name' => $g->first()->class_name, 'students' => (int) $g->max('students'),
                    'subjects' => $g->mapWithKeys(fn ($r) => [$r->subject_id => $r->asked ? round($r->correct / $r->asked * 100) : null])->all(),
                    'rate' => $g->sum('asked') ? round($g->sum('correct') / $g->sum('asked') * 100) : null,
                ])->sortBy('name')->values()->all();
        }

        $withData = $topics->filter(fn ($t) => $t['rate'] !== null);

        return [
            'topics' => $topics,
            'subjects' => $subjects,
            'hardest' => $withData->take(8)->values(),
            'easiest' => $withData->sortByDesc('rate')->take(8)->values(),
            'classes' => $classes,
            'student' => ! empty($f['student_id']) ? [
                'strong' => $withData->sortByDesc('rate')->filter(fn ($t) => $t['rate'] >= 70)->take(8)->values(),
                'weak' => $withData->filter(fn ($t) => $t['rate'] < 50)->take(8)->values(),
            ] : null,
        ];
    }

    /** Akademik Komuta Merkezi. */
    public function dashboard(): array
    {
        $today = CarbonImmutable::today();
        $exams = DB::table('exams as e')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->when(app(BranchContext::class)->id(), fn ($q, $b) => $q->where('e.branch_id', $b))
            ->leftJoin('exam_results as r', 'r.exam_id', '=', 'e.id')
            ->groupBy('e.id', 'e.name', 'e.exam_date', 'e.scope', 't.code', 't.name')->orderBy('e.exam_date')
            ->get(['e.id', 'e.name', 'e.exam_date', 'e.scope', 't.code as type_code', 't.name as type_name', DB::raw('COUNT(r.id) AS participants'), DB::raw('AVG(r.net) AS avg_net'), DB::raw('AVG(r.score) AS avg_score'), DB::raw('MAX(r.net) AS max_net')])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'exam_date' => $e->exam_date, 'scope' => $e->scope, 'type_code' => $e->type_code, 'type_group' => str_starts_with($e->type_code, 'AYT') ? 'AYT' : $e->type_code, 'type_name' => $e->type_name, 'participants' => (int) $e->participants, 'avg_net' => round((float) $e->avg_net, 2), 'avg_score' => $e->avg_score !== null ? round((float) $e->avg_score, 2) : null, 'max_net' => round((float) $e->max_net, 2)]);

        $byGroup = $exams->groupBy('type_group');
        $kpis = [];
        foreach (['TYT', 'AYT', 'LGS'] as $g) {
            $list = $byGroup->get($g, collect());
            $last = $list->last();
            $prev = $list->count() > 1 ? $list[$list->count() - 2] : null;
            $kpis[$g] = $last ? ['avg_net' => $last['avg_net'], 'participants' => $last['participants'], 'exam' => $last['name'], 'exam_id' => $last['id'], 'delta' => $prev ? round($last['avg_net'] - $prev['avg_net'], 2) : null, 'exams' => $list->count()] : null;
        }

        $recentIds = $exams->sortByDesc('exam_date')->take(12)->pluck('id')->all();

        // Sınıf karşılaştırması (son 12 sınav, tür grubuna göre)
        $classRows = $recentIds === [] ? collect() : DB::table('exam_results as r')->join('class_groups as g', 'g.id', '=', 'r.class_group_id')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->whereIn('r.exam_id', $recentIds)->groupBy('g.id', 'g.name', 't.code')
            ->get(['g.id', 'g.name', 't.code as type_code', DB::raw('COUNT(*) AS results'), DB::raw('AVG(r.net) AS avg_net'), DB::raw('COUNT(DISTINCT r.exam_id) AS exams')]);
        $classes = $classRows->groupBy('id')->map(fn ($g) => [
            'id' => $g->first()->id, 'name' => $g->first()->name,
            'types' => $g->mapWithKeys(fn ($r) => [str_starts_with($r->type_code, 'AYT') ? 'AYT' : $r->type_code => ['avg_net' => round((float) $r->avg_net, 2), 'exams' => (int) $r->exams, 'results' => (int) $r->results]])->all(),
        ])->sortBy('name')->values();

        // Gelişim: aynı tür grubunda ≥2 sınavı olan öğrencilerde son iki − ilk iki sınav ortalaması
        $movers = ['up' => [], 'down' => []];
        if ($recentIds !== []) {
            $rows = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')->join('students as s', 's.id', '=', 'r.student_id')
                ->leftJoin('class_groups as g', 'g.id', '=', 'r.class_group_id')
                ->whereIn('r.exam_id', $recentIds)->orderBy('e.exam_date')
                ->get(['r.student_id', 's.full_name', 'g.name as class_name', 't.code as type_code', 'r.net', 'e.exam_date', 'e.name as exam_name']);
            $deltas = [];
            foreach ($rows->groupBy(fn ($r) => $r->student_id.'|'.(str_starts_with($r->type_code, 'AYT') ? 'AYT' : $r->type_code)) as $key => $list) {
                if ($list->count() < 2) {
                    continue;
                }
                $nets = $list->pluck('net')->map(fn ($n) => (float) $n)->values();
                $k = $nets->count() >= 4 ? 2 : 1;
                $first = $nets->take($k)->avg();
                $last = $nets->slice(-$k)->avg();
                $r = $list->first();
                $deltas[] = ['student_id' => $r->student_id, 'name' => $r->full_name, 'class_name' => $r->class_name, 'type_group' => explode('|', $key)[1], 'from' => round($first, 2), 'to' => round($last, 2), 'delta' => round($last - $first, 2), 'exams' => $nets->count(), 'nets' => $nets->all()];
            }
            $sorted = collect($deltas)->sortByDesc('delta')->values();
            $movers['up'] = $sorted->filter(fn ($d) => $d['delta'] > 0)->take(8)->values()->all();
            $movers['down'] = $sorted->reverse()->filter(fn ($d) => $d['delta'] < 0)->take(8)->values()->all();
        }

        $topics = $this->cumulativeTopics(['min_asked' => 20]);

        $upcoming = Exam::query()->with('type:id,code,name')->where('status', '!=', 'results_published')->where('exam_date', '>=', $today->subDays(7)->toDateString())
            ->orderBy('exam_date')->limit(6)->get(['id', 'name', 'exam_date', 'status', 'exam_type_id', 'publisher'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'exam_date' => $e->exam_date->toDateString(), 'status' => $e->status, 'type_code' => $e->type?->code, 'publisher' => $e->publisher]);

        return [
            'generated_at' => now()->toIso8601String(),
            'kpis' => $kpis,
            'totals' => ['published' => $exams->count(), 'results' => $exams->sum('participants'), 'students_tested' => $recentIds === [] ? 0 : DB::table('exam_results')->whereIn('exam_id', $recentIds)->distinct('student_id')->count('student_id')],
            'trend' => $exams->values(),
            'classes' => $classes,
            'movers' => $movers,
            'topics' => ['hardest' => $topics['hardest'], 'easiest' => $topics['easiest'], 'subjects' => $topics['subjects']],
            'upcoming' => $upcoming,
        ];
    }

    /** @return list<int> */
    private function resultIds(Exam $exam, ?int $classGroupId): array
    {
        return DB::table('exam_results')->where('exam_id', $exam->id)->when($classGroupId, fn ($q) => $q->where('class_group_id', $classGroupId))->pluck('id')->all();
    }

    /** Öğrencinin aynı türdeki yayımlı sınav net geçmişi (rapor kartı grafiği için). */
    public function studentHistory(int $studentId, int $examTypeId, int $limit = 8): Collection
    {
        return DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')
            ->where('r.student_id', $studentId)->where('e.exam_type_id', $examTypeId)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->limit($limit)
            ->get(['e.id', 'e.name', 'e.exam_date', 'r.net', 'r.score', 'r.institution_rank'])->reverse()->values();
    }
}
