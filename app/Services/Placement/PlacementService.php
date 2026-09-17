<?php

namespace App\Services\Placement;

use App\Exceptions\BusinessRuleException;
use App\Models\AcademicTerm;
use App\Models\ActivityFeed;
use App\Models\ClassGroup;
use App\Models\PlacementRun;
use App\Models\Student;
use App\Services\Placement\Core\PlacementEngine;
use App\Services\Placement\Core\TrackPartitioner;
use App\Support\Audit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sınıflar ve yerleştirme ana ekranı + otomatik yerleştirme botu (önizleme → onay → uygula → geri al).
 */
class PlacementService
{
    public function __construct(
        private readonly ClassStructure $structure,
        private readonly PlacementData $data,
        private readonly MembershipWriter $writer,
    ) {}

    public function term(?int $termId): AcademicTerm
    {
        $term = $termId ? AcademicTerm::query()->find($termId) : AcademicTerm::current();
        if (! $term) {
            throw new BusinessRuleException('Dönem bulunamadı. Önce akademik dönem tanımlayın.', 'term_not_found', [], 404);
        }

        return $term;
    }

    /** Tek ekran verisi: seviye → şubeler (öğrenci kartları + özet), sınıfsızlar, bekleme listesi. */
    public function overview(AcademicTerm $term): array
    {
        $settings = $this->structure->settings();
        $groups = $this->structure->groups($term->id);
        $programs = DB::table('programs')->whereIn('id', $groups->pluck('program_id')->unique())->pluck('name', 'id');
        $levels = [];

        foreach ($settings['levels'] as $level) {
            $levelGroups = $groups->filter(fn ($g) => $g->grade_level === $level)->keyBy('section');
            $levelSections = $this->structure->sectionsFor($level);
            $roster = $this->data->roster($term->id, $level, $levelGroups);
            $waitRows = DB::table('class_waitlist as w')->join('students as s', 's.id', '=', 'w.student_id')
                ->where('w.academic_term_id', $term->id)->where('w.status', 'waiting')->where('w.grade_level', $level)->whereNull('s.deleted_at')
                ->orderBy('w.created_at')->orderBy('w.id')
                ->get(['w.id as entry_id', 'w.preferred_section', 'w.source', 'w.reason', 'w.created_at as waiting_since', 's.id', 's.full_name', 's.student_no', 's.gender', 's.status', 's.school_grade', 's.photo_path']);
            $ids = array_values(array_unique(array_merge($roster->keys()->all(), $waitRows->pluck('id')->map(fn ($v) => (int) $v)->all())));
            $scores = $this->data->scores($ids);
            $risks = $this->data->risks($ids);
            $families = $this->data->families($ids);
            $card = fn ($s) => $this->card($s, $scores, $risks, $families);

            $sections = [];
            foreach ($levelSections as $section) {
                $g = $levelGroups[$section] ?? null;
                $spec = $this->structure->spec($level, $section);
                $members = $g ? $roster->filter(fn ($s) => $s->class_group_id === $g->id)->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)->values() : collect();
                $scored = $members->map(fn ($s) => $scores[$s->id]['avg'] ?? null)->filter(fn ($v) => $v !== null);
                $sections[] = [
                    'section' => $section, 'name' => $g->name ?? ClassStructure::className($level, $section),
                    'track' => $g->track ?? $spec['track'] ?? null,
                    'class_group' => $g ? ['id' => $g->id, 'name' => $g->name, 'capacity' => $g->capacity, 'program' => $programs[$g->program_id] ?? null, 'track' => $g->track] : null,
                    'count' => $members->count(),
                    'female' => $members->where('gender', 'female')->count(), 'male' => $members->where('gender', 'male')->count(),
                    'avg_net' => $scored->isEmpty() ? null : round($scored->avg(), 2), 'scored' => $scored->count(),
                    'high_risk' => $members->filter(fn ($s) => ($risks[$s->id] ?? null) === 'high')->count(),
                    'students' => $members->map($card)->values(),
                ];
            }

            $waitIds = $waitRows->pluck('id')->map(fn ($v) => (int) $v)->all();
            $unplaced = $roster->filter(fn ($s) => $s->class_group_id === null && $s->status === 'active' && ! $s->grade_mismatch && ! in_array($s->id, $waitIds, true))
                ->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

            $levels[] = [
                'level' => $level,
                'label' => ClassStructure::levelLabel($level),
                'sectioned' => $levelSections !== [ClassStructure::NO_SECTION],
                'ready' => $levelGroups->count() === count($levelSections),
                'sections' => $sections,
                'unplaced' => $unplaced->map($card)->values(),
                'waitlist' => $waitRows->map(fn ($w) => $card((object) [
                    'id' => (int) $w->id, 'full_name' => $w->full_name, 'student_no' => $w->student_no, 'gender' => $w->gender, 'status' => $w->status,
                    'photo_path' => $w->photo_path, 'pinned' => false, 'joined_on' => null, 'grade_mismatch' => false, 'other_class' => null,
                    'class_group_id' => $roster[(int) $w->id]->class_group_id ?? null, 'section' => $roster[(int) $w->id]->section ?? null,
                ]) + [
                    'entry_id' => (int) $w->entry_id, 'preferred_section' => $w->preferred_section, 'source' => $w->source,
                    'source_label' => \App\Models\ClassWaitlistEntry::SOURCES[$w->source] ?? $w->source, 'reason' => $w->reason, 'waiting_since' => $w->waiting_since,
                ])->values(),
            ];
        }

        $runs = PlacementRun::query()->where('academic_term_id', $term->id)->with('appliedBy:id,name')->latest('id')->limit(5)->get()
            ->map(fn (PlacementRun $r) => $this->runRow($r));

        return [
            'term' => ['id' => $term->id, 'name' => $term->name, 'starts_on' => $term->starts_on?->toDateString(), 'ends_on' => $term->ends_on?->toDateString(), 'is_current' => (bool) $term->is_current],
            'terms' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'ends_on', 'is_current']),
            'settings' => $settings,
            'customized' => $this->structure->isCustomized(),
            'configured' => $this->structure->isConfigured(),
            'missing' => $this->structure->missing($term->id),
            'levels' => $levels,
            'runs' => $runs,
        ];
    }

    public function runRow(PlacementRun $r): array
    {
        $latestOpen = PlacementRun::query()->where('academic_term_id', $r->academic_term_id)->whereNull('reverted_at')->max('id');

        return [
            'id' => $r->id, 'kind' => $r->kind, 'kind_label' => PlacementRun::KINDS[$r->kind] ?? $r->kind, 'grade_levels' => $r->grade_levels,
            'summary' => $r->summary, 'applied_by' => $r->appliedBy?->name, 'created_at' => $r->created_at, 'reverted_at' => $r->reverted_at,
            'can_revert' => $r->reverted_at === null && (int) $latestOpen === $r->id,
        ];
    }

    private function card(object $s, array $scores, array $risks, array $families): array
    {
        return [
            'id' => (int) $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'gender' => $s->gender,
            'status' => $s->status, 'status_label' => Student::STATUSES[$s->status] ?? $s->status,
            'photo_url' => $s->photo_path ? Storage::disk('public')->url($s->photo_path) : null,
            'avg_net' => $scores[$s->id]['avg'] ?? null, 'exam_count' => $scores[$s->id]['count'] ?? 0,
            'risk' => $risks[$s->id] ?? null, 'sibling' => isset($families[$s->id]),
            'pinned' => (bool) ($s->pinned ?? false), 'joined_on' => $s->joined_on ?? null,
            'class_group_id' => $s->class_group_id ?? null, 'section' => $s->section ?? null,
            'grade_mismatch' => (bool) ($s->grade_mismatch ?? false), 'other_class' => $s->other_class ?? null,
        ];
    }

    /**
     * Otomatik yerleştirme planı (yazmaz). Aynı girdiyle aynı sonucu verir; hash ile uygulamada bayatlık denetlenir.
     *
     * @param  list<int>  $levels
     * @param  array<int,int>  $studentFilter  yalnız bu öğrenciler (demo dönüşümü); boşsa hepsi
     */
    public function plan(AcademicTerm $term, array $levels, string $mode, ?bool $siblingsApart = null, array $studentFilter = []): array
    {
        $settings = $this->structure->settings();
        $siblingsApart ??= $settings['siblings_apart'];
        $groups = $this->structure->groups($term->id);
        $engine = new PlacementEngine;
        $out = [];

        foreach ($levels as $level) {
            $levelGroups = $groups->filter(fn ($g) => $g->grade_level === $level)->keyBy('section');
            if ($levelGroups->isEmpty()) {
                $out[] = ['level' => $level, 'skipped' => true, 'warnings' => ["{$level}. sınıf şubeleri bu dönemde tanımlı değil. Önce sınıf yapısını oluşturun."]];
                continue;
            }
            $roster = $this->data->roster($term->id, $level, $levelGroups)
                ->filter(fn ($s) => $s->class_group_id !== null || ($s->status === 'active' && ! $s->grade_mismatch))
                ->when($studentFilter !== [], fn (Collection $c) => $c->filter(fn ($s) => in_array($s->id, $studentFilter, true) || $s->class_group_id !== null));
            $ids = $roster->keys()->map(fn ($v) => (int) $v)->all();
            $scores = $this->data->scores($ids);
            $risks = $this->data->risks($ids);
            $families = $this->data->families($ids);
            $result = $this->placeByTrack($engine, $levelGroups, $roster, $this->data->candidates($roster, $scores, $risks, $families), $mode, $siblingsApart);

            $sectionNames = $levelGroups->map(fn ($g) => $g->name)->all();
            $name = fn (?string $section) => $section === null ? null : ($sectionNames[$section] ?? ClassStructure::className($level, $section));
            $changes = [];
            foreach (array_merge($result['moved'], $result['placed_new']) as $sid) {
                $s = $roster[$sid];
                $to = $result['assignments'][$sid] ?? null;
                $changes[] = [
                    'student_id' => $sid, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'gender' => $s->gender,
                    'avg_net' => $scores[$sid]['avg'] ?? null, 'risk' => $risks[$sid] ?? null,
                    'from' => $name($s->section) ?? ($s->other_class['name'] ?? null), 'to' => $name($to), 'to_waitlist' => $to === null,
                    'kind' => $to === null ? 'waitlist' : ($s->section === null ? 'placed' : 'moved'),
                ];
            }
            foreach ($result['waitlist'] as $sid) {
                if ($roster[$sid]->section === null) {
                    $s = $roster[$sid];
                    $changes[] = ['student_id' => $sid, 'full_name' => $s->full_name, 'student_no' => $s->student_no, 'gender' => $s->gender,
                        'avg_net' => $scores[$sid]['avg'] ?? null, 'risk' => $risks[$sid] ?? null, 'from' => $s->other_class['name'] ?? null, 'to' => null, 'to_waitlist' => true, 'kind' => 'waitlist'];
                }
            }
            usort($changes, fn ($a, $b) => [$a['kind'], $a['to'] ?? 'Z', $a['full_name']] <=> [$b['kind'], $b['to'] ?? 'Z', $b['full_name']]);

            $out[] = [
                'level' => $level, 'skipped' => false,
                'group_ids' => $levelGroups->map(fn ($g) => $g->id)->all(),
                'candidates' => count($ids),
                'assignments' => $result['assignments'], 'waitlist' => $result['waitlist'],
                'current' => $roster->map(fn ($s) => [$s->section, $s->pinned, $s->other_class['id'] ?? null])->all(),
                'moved' => count($result['moved']) - count(array_filter($result['waitlist'], fn ($id) => $roster[$id]->section !== null)),
                'placed' => count($result['placed_new']),
                'waitlisted' => count($result['waitlist']),
                'before' => $result['before'], 'after' => $result['after'],
                'score_before' => $result['score_before'], 'score_after' => $result['score_after'],
                'warnings' => $result['warnings'], 'changes' => $changes,
            ];
        }

        $hash = sha1(json_encode([$term->id, $mode, $siblingsApart, array_map(fn ($l) => $l['skipped'] ? [$l['level']] : [$l['level'], $l['assignments'], $l['waitlist'], $l['current'], $l['group_ids']], $out)]));

        return [
            'term_id' => $term->id, 'mode' => $mode, 'siblings_apart' => $siblingsApart, 'hash' => $hash, 'levels' => $out,
            'totals' => [
                'moved' => array_sum(array_map(fn ($l) => $l['moved'] ?? 0, $out)),
                'placed' => array_sum(array_map(fn ($l) => $l['placed'] ?? 0, $out)),
                'waitlisted' => array_sum(array_map(fn ($l) => $l['waitlisted'] ?? 0, $out)),
            ],
        ];
    }

    /**
     * Şubeler farklı alanlara ayrılmışsa motor her alan kovasında ayrı çalışır; sonuçlar birleştirilir.
     *
     * @param  list<\App\Services\Placement\Core\Candidate>  $candidates
     */
    private function placeByTrack(PlacementEngine $engine, Collection $levelGroups, Collection $roster, array $candidates, string $mode, bool $siblingsApart): array
    {
        $sorted = $levelGroups->sortKeys();
        $sections = $sorted->map(fn ($g) => ['track' => $g->track, 'capacity' => $g->capacity])->all();
        $students = $roster->map(fn ($s) => ['field' => $s->field ?? null, 'current' => $s->section, 'pinned' => (bool) $s->pinned])->all();
        $split = TrackPartitioner::split($sections, $students, $mode, ClassStructure::TRACKS);
        $byId = [];
        foreach ($candidates as $c) {
            $byId[$c->id] = $c;
        }

        $merged = ['assignments' => [], 'waitlist' => [], 'moved' => [], 'placed_new' => [], 'targets' => [], 'before' => [], 'after' => [],
            'score_before' => null, 'score_after' => 0, 'warnings' => $split['warnings']];
        $weights = ['before' => 0, 'after' => 0];
        $sums = ['before' => 0.0, 'after' => 0.0];
        foreach ($split['buckets'] as $bucket) {
            $caps = [];
            foreach ($bucket['sections'] as $code) {
                $caps[$code] = $sections[$code]['capacity'];
            }
            $pool = array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $bucket['students'])));
            $r = $engine->place($caps, $pool, ['mode' => $mode, 'siblings_apart' => $siblingsApart]);
            if (count($split['buckets']) > 1 && $bucket['track'] !== TrackPartitioner::GENERAL) {
                $label = ClassStructure::TRACKS[$bucket['track']] ?? $bucket['track'];
                $r['warnings'] = array_map(fn ($w) => "{$label}: {$w}", $r['warnings']);
            }
            $merged['assignments'] += $r['assignments'];
            foreach (['waitlist', 'moved', 'placed_new', 'before', 'after', 'warnings'] as $k) {
                $merged[$k] = array_merge($merged[$k], $r[$k]);
            }
            $merged['targets'] += $r['targets'];
            $n = count($pool);
            if ($r['score_before'] !== null) {
                $sums['before'] += $r['score_before'] * $n;
                $weights['before'] += $n;
            }
            $sums['after'] += $r['score_after'] * $n;
            $weights['after'] += $n;
        }
        ksort($merged['assignments']);
        sort($merged['waitlist']);
        sort($merged['moved']);
        sort($merged['placed_new']);
        $order = array_flip(array_keys($sections));
        foreach (['before', 'after'] as $k) {
            usort($merged[$k], fn ($a, $b) => ($order[$a['section']] ?? 99) <=> ($order[$b['section']] ?? 99));
        }
        $merged['score_before'] = $weights['before'] > 0 ? (int) round($sums['before'] / $weights['before']) : null;
        $merged['score_after'] = $weights['after'] > 0 ? (int) round($sums['after'] / $weights['after']) : 100;
        $merged['warnings'] = array_values(array_unique($merged['warnings']));
        if (count($split['buckets']) > 1) {
            $merged['warnings'][] = 'Şubeler alana göre ayrı dengelendi: '.implode(' · ', array_map(fn ($b) => ($b['track'] === TrackPartitioner::GENERAL ? 'Genel' : (ClassStructure::TRACKS[$b['track']] ?? $b['track'])).' → '.implode(', ', array_map(fn ($c) => $levelGroups[$c]->name, $b['sections'])).' ('.count($b['students']).' öğrenci)', $split['buckets'])).'.';
        }

        return $merged;
    }

    /** İstemciye dönen önizleme (iç alanlar çıkarılır). */
    public function preview(AcademicTerm $term, array $levels, string $mode, ?bool $siblingsApart = null): array
    {
        $plan = $this->plan($term, $levels, $mode, $siblingsApart);
        $plan['levels'] = array_map(fn ($l) => array_diff_key($l, array_flip(['assignments', 'current', 'group_ids'])), $plan['levels']);

        return $plan;
    }

    /**
     * Planı uygular (tek transaction). $hash önizlemedekiyle aynı değilse durum değişmiştir → reddedilir.
     * $inTransaction: çağıran zaten transaction açtıysa (demo dönüşümü) iç transaction açılmaz.
     */
    public function apply(AcademicTerm $term, array $levels, string $mode, ?bool $siblingsApart, ?string $hash, ?string $effectiveOn = null, array $options = []): PlacementRun
    {
        $work = function () use ($term, $levels, $mode, $siblingsApart, $hash, $effectiveOn, $options) {
            ClassGroup::query()->where('academic_term_id', $term->id)->lockForUpdate()->get(['id']);
            $plan = $this->plan($term, $levels, $mode, $siblingsApart, $options['student_filter'] ?? []);
            if ($hash !== null && ! hash_equals($plan['hash'], $hash)) {
                throw new BusinessRuleException('Önizlemeden sonra sınıflarda değişiklik oldu. Önizlemeyi yenileyip tekrar deneyin.', 'placement_stale', [], 409);
            }
            $totals = $plan['totals'];
            if ($totals['moved'] + $totals['placed'] + $totals['waitlisted'] === 0) {
                throw new BusinessRuleException('Uygulanacak değişiklik yok; sınıflar zaten dengeli.', 'placement_no_changes');
            }

            $date = $this->effectiveDate($term, $effectiveOn);
            $run = PlacementRun::query()->create([
                'academic_term_id' => $term->id, 'kind' => $options['kind'] ?? 'auto', 'grade_levels' => array_values($levels),
                'summary' => [], 'snapshot' => [], 'applied_by' => Auth::id(),
            ]);
            $cs = $options['change_set'] ?? new ChangeSet;
            $source = $options['source'] ?? 'placement';
            $reason = $options['reason'] ?? 'Otomatik sınıf yerleştirme';

            $levelSummary = [];
            foreach ($plan['levels'] as $l) {
                if ($l['skipped']) {
                    continue;
                }
                foreach ($l['assignments'] as $sid => $section) {
                    if ($l['current'][$sid][0] !== $section || $l['current'][$sid][2] !== null) {
                        $this->writer->move($sid, $term->id, $l['group_ids'][$section], $date, $reason, $source, $run->id, $cs);
                    }
                }
                foreach ($l['waitlist'] as $sid) {
                    if ($l['current'][$sid][0] !== null) {
                        $this->writer->move($sid, $term->id, null, $date, $reason.' — kapasite yetersiz', $source, $run->id, $cs);
                    }
                    $this->writer->addWaitlist($sid, $term->id, $l['level'], null, $options['waitlist_source'] ?? 'placement', 'Seviyede yer kalmadı (kapasite '.array_sum(array_map(fn ($m) => $m['capacity'], $l['after'])).').', $run->id, $cs);
                }
                $levelSummary[] = ['level' => $l['level'], 'moved' => $l['moved'], 'placed' => $l['placed'], 'waitlisted' => $l['waitlisted'], 'score_before' => $l['score_before'], 'score_after' => $l['score_after']];
            }

            $summary = ['mode' => $mode, 'effective_on' => $date, 'levels' => $levelSummary] + $totals;
            if (! ($options['defer_snapshot'] ?? false)) {
                $run->forceFill(['summary' => $summary, 'snapshot' => $cs->toArray()])->save();
            } else {
                $run->forceFill(['summary' => $summary])->save();
            }

            $levelText = implode(', ', array_map(fn ($l) => "{$l['level']}.", $levelSummary));
            $text = sprintf('%s sınıflarda otomatik yerleştirme uyguladı: %d taşındı, %d yerleşti, %d bekleme listesine alındı.', $levelText, $totals['moved'], $totals['placed'], $totals['waitlisted']);
            Audit::log('placement.applied', $text." (işlem #{$run->id})");
            ActivityFeed::query()->create(['kind' => 'placement', 'message' => ucfirst($text), 'meta' => ['placement_run_id' => $run->id], 'occurred_at' => now()]);
            $this->writer->flushEvents();

            return $run;
        };

        return ($options['in_transaction'] ?? false) ? $work() : DB::transaction($work);
    }

    public function revert(PlacementRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = PlacementRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->reverted_at) {
                throw new BusinessRuleException('Bu işlem zaten geri alınmış.', 'placement_already_reverted');
            }
            $newer = PlacementRun::query()->where('academic_term_id', $run->academic_term_id)->where('id', '>', $run->id)->whereNull('reverted_at')->exists();
            if ($newer) {
                throw new BusinessRuleException('Daha sonra yapılmış bir toplu işlem var. Önce en son işlemi geri alın.', 'placement_newer_run');
            }
            $stats = $this->writer->revert(ChangeSet::fromArray($run->snapshot ?? []));
            $run->forceFill(['reverted_at' => now(), 'reverted_by' => Auth::id()])->save();

            $label = PlacementRun::KINDS[$run->kind] ?? $run->kind;
            Audit::log('placement.reverted', "#{$run->id} numaralı \"{$label}\" işlemini geri aldı ({$stats['students']} öğrenci bilgisi, {$stats['memberships']} sınıf üyeliği geri yüklendi).");
            ActivityFeed::query()->create(['kind' => 'placement', 'message' => "\"{$label}\" işlemi geri alındı", 'meta' => ['placement_run_id' => $run->id], 'occurred_at' => now()]);
        });
    }

    public function pin(Student $student, AcademicTerm $term, bool $pinned): void
    {
        $groupIds = $this->structure->groups($term->id)->pluck('id');
        $affected = DB::table('class_group_student')->where('student_id', $student->id)->whereNull('left_on')->whereIn('class_group_id', $groupIds)
            ->update(['is_pinned' => $pinned, 'updated_at' => now()]);
        if (! $affected) {
            throw new BusinessRuleException('Öğrenci bu dönemde bir şubede değil; yalnız şubedeki öğrenci sabitlenebilir.', 'placement_not_member');
        }
        Audit::log($pinned ? 'placement.pinned' : 'placement.unpinned', $pinned
            ? "{$student->full_name} öğrencisini şubesine sabitledi (otomatik yerleştirme taşımaz)."
            : "{$student->full_name} öğrencisinin şube sabitlemesini kaldırdı.", $student);
    }

    /** @param array{capacity?:int, siblings_apart?:bool} $values */
    public function saveSettings(array $values): array
    {
        $before = $this->structure->settings();
        $put = array_intersect_key($values, array_flip(['capacity', 'siblings_apart']));
        if (isset($put['capacity']) && $this->structure->isCustomized()) {
            // Özelleştirilmiş yapıda yeni şubelerin varsayılan kapasitesi de güncellenir (mevcut şube tanımları değişmez)
            $put['structure'] = ClassStructure::storable(['default_capacity' => (int) $put['capacity']] + $this->structure->structure());
        }
        Settings::put('classes', $put);
        $this->structure->flush();
        $after = $this->structure->settings();
        Audit::log('settings.classes_updated', sprintf('Sınıf ayarlarını güncelledi (kapasite %d → %d, kardeşleri ayır: %s → %s). Mevcut sınıfların kontenjanı değişmez.',
            $before['capacity'], $after['capacity'], $before['siblings_apart'] ? 'açık' : 'kapalı', $after['siblings_apart'] ? 'açık' : 'kapalı'), null, ['before' => $before, 'after' => $after]);

        return $after;
    }

    public function ensureStructure(AcademicTerm $term, bool $updateExisting = false): array
    {
        return DB::transaction(function () use ($term, $updateExisting) {
            ClassGroup::query()->where('academic_term_id', $term->id)->lockForUpdate()->get(['id']);
            $r = $this->structure->ensure($term, false, $updateExisting);
            if ($r['created'] || $r['adopted'] || $r['reactivated'] || $r['updated']) {
                Audit::log('placement.structure_ensured', sprintf('%s dönemi sınıf yapısını uyguladı: %d sınıf oluşturuldu, %d sınıf eşleştirildi, %d sınıf güncellendi.', $term->name, count($r['created']), count($r['adopted']) + count($r['reactivated']), count($r['updated'])));
            }

            return $r;
        });
    }

    /** Geçerlilik tarihi: dönem başından önce olamaz, bugünden (dönem henüz başlamadıysa dönem başından) sonra olamaz. */
    public function effectiveDate(AcademicTerm $term, ?string $value): string
    {
        $start = CarbonImmutable::parse($term->starts_on);
        $today = CarbonImmutable::today();
        $max = $today->lt($start) ? $start : $today;
        $date = $value ? CarbonImmutable::parse($value) : $max;
        if ($date->lt($start) || $date->gt($max)) {
            throw new BusinessRuleException(sprintf('Geçerlilik tarihi %s ile %s arasında olmalı.', $start->format('d.m.Y'), $max->format('d.m.Y')), 'placement_invalid_date');
        }

        return $date->toDateString();
    }
}
