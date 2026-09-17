<?php

namespace App\Services\Academic\Timetable;

use App\Exceptions\BusinessRuleException;
use App\Jobs\Academic\RunTimetableSolver;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\LessonSchedule;
use App\Models\LessonSession;
use App\Models\TimetableRun;
use App\Services\Academic\ScheduleConflicts;
use App\Services\Academic\SessionGenerator;
use App\Services\Academic\TimeSlots;
use App\Support\Audit;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Program botu yaşam döngüsü: kuyruğa al → çalıştır (öneri) → önizle → uygula / vazgeç → geri al.
 *
 * Uygula (tek transaction): seçili sınıfların kilitsiz şablonları uygulama tarihinden itibaren
 * sonlandırılır (geçmiş ve yoklaması alınmış/iptal edilmiş oturumlar korunur), öneri yeni şablon
 * olarak yazılır, gelecek oturumlar SessionGenerator ile üretilir. Önceki durumun anlık görüntüsü saklanır.
 */
class TimetableService
{
    public const HORIZON_DAYS = 21;

    public function __construct(private readonly TimetableProblemBuilder $builder, private readonly ScheduleConflicts $conflicts, private readonly SessionGenerator $generator) {}

    public static function defaultSettings(): array
    {
        return ['weights' => Weights::DEFAULTS, 'max_per_day' => 2, 'time_limit' => 20, 'seed' => 20260916];
    }

    public function queue(int $termId, array $classIds, array $settings, ?int $userId): TimetableRun
    {
        $term = AcademicTerm::query()->findOrFail($termId);
        $valid = ClassGroup::query()->where('academic_term_id', $term->id)->whereIn('id', $classIds)->where('is_active', true)->pluck('id')->all();
        if ($valid === []) {
            throw new BusinessRuleException('Planlanacak en az bir aktif sınıf seçin.', 'no_classes');
        }
        if (TimetableRun::query()->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subMinutes(10))->exists()) {
            throw new BusinessRuleException('Bir program botu çalışması zaten sürüyor. Bitince yeniden deneyin.', 'run_in_progress');
        }

        $defaults = self::defaultSettings();
        $settings = [
            'weights' => Weights::normalize($settings['weights'] ?? []),
            'max_per_day' => max(1, min(6, (int) ($settings['max_per_day'] ?? $defaults['max_per_day']))),
            'time_limit' => max(5, min(60, (int) ($settings['time_limit'] ?? $defaults['time_limit']))),
            'seed' => (int) ($settings['seed'] ?? $defaults['seed']),
        ];

        $run = TimetableRun::query()->create([
            'academic_term_id' => $term->id, 'class_group_ids' => array_values(array_map('intval', $valid)), 'settings' => $settings,
            'status' => 'queued', 'progress' => 0, 'created_by' => $userId,
            'log' => [['t' => now()->format('H:i:s'), 'm' => count($valid).' sınıf için çalışma sıraya alındı. Kuyruk işçisi en geç bir dakika içinde başlar.']],
        ]);
        $branchId = (int) $run->branch_id;
        DB::afterCommit(fn () => RunTimetableSolver::dispatch($run->id, $branchId));

        return $run;
    }

    /** Kuyruk işinden çağrılır (şube bağlamı açık). */
    public function execute(TimetableRun $run): void
    {
        if (! in_array($run->status, ['queued', 'failed'], true)) {
            return;
        }
        $log = $run->log ?? [];
        $run->forceFill(['status' => 'running', 'started_at' => now(), 'progress' => 1, 'error' => null])->save();

        $from = CarbonImmutable::tomorrow();
        $built = $this->builder->build($run->academic_term_id, $run->class_group_ids, $run->settings, $from);
        foreach ($built['warnings'] as $w) {
            $log[] = ['t' => now()->format('H:i:s'), 'm' => $w];
        }

        $solver = new Solver($built['problem'], ['time_limit' => $run->settings['time_limit'] ?? 20, 'seed' => $run->settings['seed'] ?? 1]);
        $result = $solver->solve(function (int $pct, ?string $message) use ($run, &$log) {
            if ($message !== null) {
                $log[] = ['t' => now()->format('H:i:s'), 'm' => $message];
                $log = array_slice($log, -80);
            }
            TimetableRun::query()->whereKey($run->id)->update(['progress' => $pct, 'log' => json_encode($log, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        });

        $result['names'] = $built['names'];
        $result['locked'] = $built['locked'];
        $result['external'] = $built['external'];
        $result['warnings'] = $built['warnings'];

        $run->forceFill([
            'status' => 'completed', 'progress' => 100, 'log' => $log, 'result' => $result, 'finished_at' => now(),
            'penalty' => $result['penalty'], 'quality' => $result['quality'], 'required_count' => $result['required'], 'placed_count' => $result['placed'],
            'unplaced_count' => $result['required'] - $result['placed'], 'hard_violations' => $result['hard_violations'], 'duration_ms' => $result['stats']['total_ms'],
        ])->save();
    }

    public function discard(TimetableRun $run): void
    {
        if (! in_array($run->status, ['completed', 'failed'], true)) {
            throw new BusinessRuleException('Yalnızca bekleyen bir öneriden vazgeçilebilir.', 'not_discardable');
        }
        $run->forceFill(['status' => 'discarded'])->save();
        Audit::log('timetable.discarded', "Program botu önerisinden (#{$run->id}) vazgeçti.");
    }

    // ------------------------------------------------------------------ uygula

    public function apply(TimetableRun $run, CarbonImmutable $applyFrom, ?int $userId): array
    {
        if ($run->status !== 'completed') {
            throw new BusinessRuleException('Bu öneri uygulanamaz (durum: '.(TimetableRun::STATUS_LABELS[$run->status] ?? $run->status).').', 'not_applicable');
        }
        if ($applyFrom->lte(CarbonImmutable::today())) {
            throw new BusinessRuleException('Uygulama tarihi en erken yarın olabilir (bugünkü dersler korunur).', 'apply_from_too_early');
        }
        $placements = $run->result['placements'] ?? [];
        if ($placements === []) {
            throw new BusinessRuleException('Öneride yerleşmiş ders yok.', 'empty_proposal');
        }
        if (($run->result['hard_violations'] ?? 0) > 0) {
            throw new BusinessRuleException('Öneride sert kısıt ihlali var; uygulanamaz.', 'proposal_invalid');
        }
        if (TimetableRun::query()->where('status', 'applied')->whereKeyNot($run->id)->where('applied_at', '>', $run->created_at)->exists()) {
            throw new BusinessRuleException('Bu öneri hazırlandıktan sonra başka bir öneri uygulandı. Botu yeniden çalıştırın.', 'proposal_stale');
        }

        $classIds = $run->class_group_ids;
        $term = AcademicTerm::query()->find($run->academic_term_id);

        return DB::transaction(function () use ($run, $applyFrom, $userId, $classIds, $placements, $term) {
            $old = LessonSchedule::query()->whereIn('class_group_id', $classIds)->where('is_locked', false)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $applyFrom->toDateString()))
                ->lockForUpdate()->get();

            $snapshot = [];
            $removedSessions = 0;
            foreach ($old as $s) {
                $removedSessions += $this->untouchedFrom($s->id, $applyFrom)->delete();
                $row = ['id' => $s->id, 'prev_valid_until' => $s->valid_until?->toDateString(), 'valid_from' => $s->valid_from->toDateString()];
                if ($s->valid_from->gte($applyFrom)) {
                    $s->delete();
                    $snapshot[] = $row + ['action' => 'deleted'];
                } else {
                    $s->forceFill(['valid_until' => $applyFrom->subDay()->toDateString()])->save();
                    $snapshot[] = $row + ['action' => 'ended'];
                }
            }

            $newIds = [];
            $validUntil = $term?->ends_on && $term->ends_on->gte($applyFrom) ? $term->ends_on->toDateString() : null;
            foreach ($placements as $p) {
                $newIds[] = LessonSchedule::query()->create([
                    'academic_term_id' => $run->academic_term_id, 'class_group_id' => $p['class'], 'subject_id' => $p['subject'], 'teacher_id' => $p['teacher'],
                    'classroom_id' => $p['room'], 'weekday' => $p['weekday'], 'starts_at' => TimeSlots::normalize($p['start']), 'ends_at' => TimeSlots::normalize($p['end']),
                    'valid_from' => $applyFrom->toDateString(), 'valid_until' => $validUntil, 'is_locked' => false,
                ])->id;
            }

            // Öneri hazırlandıktan sonra program değiştiyse (başka sınıfa elle ders eklendi vb.) uygulama durur.
            $newSet = array_flip($newIds);
            foreach (LessonSchedule::query()->whereIn('id', $newIds)->get() as $s) {
                $found = $this->conflicts->forSchedule(['teacher_id' => $s->teacher_id, 'classroom_id' => $s->classroom_id, 'class_group_id' => $s->class_group_id,
                    'weekday' => $s->weekday, 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'valid_from' => $s->valid_from->toDateString(), 'valid_until' => $s->valid_until?->toDateString()], $s->id);
                $external = array_values(array_filter($found, fn ($c) => ! isset($newSet[$c['schedule_id']])));
                if ($found !== [] && count($external) !== count($found)) {
                    throw new BusinessRuleException('Öneri kendi içinde çakışıyor; uygulanmadı.', 'proposal_invalid', ['conflicts' => $found], 409);
                }
                if ($external !== []) {
                    throw new BusinessRuleException('Öneri hazırlandıktan sonra program değişmiş: '.$external[0]['message'].' Botu yeniden çalıştırın.', 'proposal_stale', ['conflicts' => $external], 409);
                }
            }

            $generated = $this->regenerate($applyFrom);

            $run->forceFill(['status' => 'applied', 'apply_from' => $applyFrom->toDateString(), 'applied_at' => now(), 'applied_by' => $userId,
                'snapshot' => ['old' => $snapshot, 'new_ids' => $newIds, 'removed_sessions' => $removedSessions]])->save();

            $names = collect($run->result['names']['classes'] ?? [])->pluck('name')->implode(', ');
            Audit::log('timetable.applied', sprintf('Program botu önerisini (#%d) %s tarihinden itibaren uyguladı: %s — %d ders saati yazıldı, %d eski şablon sonlandırıldı, %d gelecek oturum yeniden üretildi.',
                $run->id, $applyFrom->format('d.m.Y'), $names, count($newIds), count($snapshot), $generated), null, ['after' => ['run_id' => $run->id, 'new' => count($newIds), 'old' => count($snapshot)]]);

            return ['created' => count($newIds), 'ended' => count($snapshot), 'removed_sessions' => $removedSessions, 'generated_sessions' => $generated];
        });
    }

    // ------------------------------------------------------------------ geri al

    public function rollback(TimetableRun $run): array
    {
        if ($run->status !== 'applied' || ! $run->snapshot) {
            throw new BusinessRuleException('Yalnızca uygulanmış bir öneri geri alınabilir.', 'not_applied');
        }
        if (TimetableRun::query()->where('status', 'applied')->whereKeyNot($run->id)->where('applied_at', '>', $run->applied_at)->exists()) {
            throw new BusinessRuleException('Bundan sonra başka bir öneri uygulanmış. Önce en son uygulananı geri alın.', 'newer_applied');
        }

        return DB::transaction(function () use ($run) {
            $today = CarbonImmutable::today();
            $tomorrow = $today->addDay();
            $applyFrom = CarbonImmutable::parse($run->apply_from);
            $snap = $run->snapshot;
            $notStarted = $applyFrom->gt($today);
            $restored = [];

            $newSchedules = LessonSchedule::query()->whereIn('id', $snap['new_ids'])->lockForUpdate()->get();
            foreach ($newSchedules as $s) {
                $this->untouchedFrom($s->id, $tomorrow)->delete();
                if ($notStarted) {
                    $s->delete();
                } else {
                    $s->forceFill(['valid_until' => $today->toDateString()])->save();
                }
            }

            foreach ($snap['old'] as $row) {
                $s = LessonSchedule::withTrashed()->find($row['id']);
                if (! $s) {
                    continue;
                }
                if ($notStarted) {
                    if ($row['action'] === 'deleted') {
                        $s->restore();
                    } else {
                        $s->forceFill(['valid_until' => $row['prev_valid_until']])->save();
                    }
                    $restored[] = $s->fresh();
                } else {
                    // Yeni program bir süre işledi: eskisinin kopyası yarından itibaren geçerli olur (geçmiş karışmaz).
                    $validFrom = max($row['valid_from'], $tomorrow->toDateString());
                    if ($row['prev_valid_until'] !== null && $row['prev_valid_until'] < $validFrom) {
                        continue;
                    }
                    $restored[] = LessonSchedule::query()->create([
                        ...$s->only(['academic_term_id', 'class_group_id', 'subject_id', 'teacher_id', 'classroom_id', 'weekday', 'starts_at', 'ends_at']),
                        'valid_from' => $validFrom, 'valid_until' => $row['prev_valid_until'], 'is_locked' => false,
                    ]);
                }
            }

            foreach ($restored as $s) {
                $found = $this->conflicts->forSchedule(['teacher_id' => $s->teacher_id, 'classroom_id' => $s->classroom_id, 'class_group_id' => $s->class_group_id,
                    'weekday' => $s->weekday, 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at, 'valid_from' => $s->valid_from->toDateString(), 'valid_until' => $s->valid_until?->toDateString()], $s->id);
                $restoredIds = array_flip(array_map(fn ($x) => $x->id, $restored));
                $external = array_values(array_filter($found, fn ($c) => ! isset($restoredIds[$c['schedule_id']])));
                if ($external !== []) {
                    throw new BusinessRuleException('Geri alınamadı: eski program bugünkü programla çakışıyor — '.$external[0]['message'], 'rollback_conflict', ['conflicts' => $external], 409);
                }
            }

            $generated = $this->regenerate($tomorrow);
            $run->forceFill(['status' => 'rolled_back', 'rolled_back_at' => now()])->save();

            Audit::log('timetable.rolled_back', sprintf('Program botu önerisini (#%d) geri aldı: %d yeni şablon kaldırıldı, %d eski şablon geri yüklendi, %d oturum yeniden üretildi.',
                $run->id, $newSchedules->count(), count($restored), $generated));

            return ['removed' => $newSchedules->count(), 'restored' => count($restored), 'generated_sessions' => $generated];
        });
    }

    // ------------------------------------------------------------------ önizleme

    /** Sınıf bazında öneri × mevcut program farkı. */
    public function preview(TimetableRun $run): array
    {
        $result = $run->result ?? [];
        $names = $result['names'] ?? [];
        $from = $run->apply_from ? CarbonImmutable::parse($run->apply_from) : CarbonImmutable::tomorrow();

        $current = in_array($run->status, ['completed'], true)
            ? LessonSchedule::query()->whereIn('class_group_id', $run->class_group_ids)->where('is_locked', false)
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $from->toDateString()))
                ->get(['id', 'class_group_id', 'subject_id', 'teacher_id', 'classroom_id', 'weekday', 'starts_at', 'ends_at'])
                ->groupBy('class_group_id')
            : collect();

        $label = fn (string $type, $id) => $names[$type][$id] ?? ($type === 'classes' ? null : '—');
        $item = fn ($x) => [
            'weekday' => (int) $x['weekday'], 'start' => substr($x['start'], 0, 5), 'end' => substr($x['end'], 0, 5),
            'subject' => ['id' => (int) $x['subject'], 'name' => $label('subjects', $x['subject'])],
            'teacher' => ['id' => (int) $x['teacher'], 'name' => $label('teachers', $x['teacher'])],
            'room' => ['id' => (int) $x['room'], 'name' => $label('rooms', $x['room'])],
        ];

        $classes = [];
        foreach ($run->class_group_ids as $cid) {
            $proposed = array_values(array_filter($result['placements'] ?? [], fn ($p) => (int) $p['class'] === (int) $cid));
            $cur = collect($current[$cid] ?? [])->map(fn ($s) => ['weekday' => $s->weekday, 'start' => substr($s->starts_at, 0, 5), 'end' => substr($s->ends_at, 0, 5), 'subject' => $s->subject_id, 'teacher' => $s->teacher_id, 'room' => $s->classroom_id]);
            $curByKey = $cur->keyBy(fn ($x) => $x['weekday'].'|'.$x['start']);
            $seen = [];
            $items = [];
            foreach ($proposed as $p) {
                $key = $p['weekday'].'|'.$p['start'];
                $before = $curByKey[$key] ?? null;
                $seen[$key] = true;
                $diff = ! $before ? 'added' : (((int) $before['subject'] === (int) $p['subject'] && (int) $before['teacher'] === (int) $p['teacher'] && (int) $before['room'] === (int) $p['room']) ? 'same' : 'changed');
                if ($run->status !== 'completed') {
                    $diff = 'same'; // uygulanmış/geri alınmış öneride fark anlamsız (mevcut program değişti)
                }
                $items[] = $item($p) + ['diff' => $diff, 'before' => $diff === 'changed' ? $item($before) : null];
            }
            $removed = $run->status === 'completed' ? $cur->filter(fn ($x, $k) => ! isset($seen[$x['weekday'].'|'.$x['start']]))->map($item)->values()->all() : [];
            $locked = array_values(array_map($item, array_filter($result['locked'] ?? [], fn ($l) => (int) $l['class'] === (int) $cid)));

            $classes[] = [
                'id' => (int) $cid, 'name' => $names['classes'][$cid]['name'] ?? ClassGroup::query()->whereKey($cid)->value('name'),
                'size' => $names['classes'][$cid]['size'] ?? null, 'slots' => $names['classes'][$cid]['slots'] ?? null,
                'items' => $items, 'locked' => $locked, 'removed' => $removed,
                'counts' => ['added' => count(array_filter($items, fn ($i) => $i['diff'] === 'added')), 'changed' => count(array_filter($items, fn ($i) => $i['diff'] === 'changed')),
                    'same' => count(array_filter($items, fn ($i) => $i['diff'] === 'same')), 'removed' => count($removed)],
                'unplaced' => array_values(array_filter($result['unplaced'] ?? [], fn ($u) => (int) $u['class'] === (int) $cid)),
            ];
        }

        return [
            'classes' => $classes,
            'unplaced' => array_map(fn ($u) => $u + ['class_name' => $names['classes'][$u['class']]['name'] ?? null, 'subject_name' => $label('subjects', $u['subject'])], $result['unplaced'] ?? []),
            'teacher_loads' => array_map(fn ($t) => $t + ['name' => $label('teachers', $t['teacher']), 'target' => null], $result['teacher_loads'] ?? []),
            'external' => $result['external'] ?? [],
            'components' => $result['components'] ?? [],
            'stats' => $result['stats'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'violations' => $result['violations'] ?? [],
        ];
    }

    // ------------------------------------------------------------------ yardımcılar

    /** Şablonun verilen tarihten itibaren dokunulmamış (yoklamasız, iptal edilmemiş) oturumları. */
    private function untouchedFrom(int $scheduleId, CarbonImmutable $from)
    {
        return LessonSession::query()->where('lesson_schedule_id', $scheduleId)->where('date', '>=', $from->toDateString())
            ->where('status', 'scheduled')->whereNull('attendance_taken_at')->whereDoesntHave('attendances');
    }

    private function regenerate(CarbonImmutable $from): int
    {
        $branchId = app(BranchContext::class)->require();
        $horizon = CarbonImmutable::today()->addDays(self::HORIZON_DAYS);
        $maxExisting = LessonSession::query()->max('date');
        $to = $maxExisting ? $horizon->max(CarbonImmutable::parse($maxExisting)) : $horizon;

        return $from->lte($to) ? $this->generator->generate($branchId, $from, $to) : 0;
    }
}
