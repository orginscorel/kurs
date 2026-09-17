<?php

namespace App\Console\Commands;

use App\Models\AcademicTerm;
use App\Models\ActivityFeed;
use App\Models\Branch;
use App\Services\Placement\ChangeSet;
use App\Services\Placement\ClassStructure;
use App\Services\Placement\MembershipWriter;
use App\Services\Placement\PlacementService;
use App\Support\Audit;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Demo veriyi gerçek kurum yapısına dönüştürür: 9-12 × A/B, kapasite 15.
 * Yalnız "Demo" etiketli öğrencilere dokunur. Tek transaction; --dry-run aynı adımları çalıştırıp
 * transaction'ı geri alır (hiçbir şey kalıcı yazılmaz) ve tam raporu basar.
 * Geri alma: oluşan placement_runs satırı (kind=restructure) sınıflar ekranından geri alınabilir.
 */
class RestructureDemoClasses extends Command
{
    protected $signature = 'kurs:demo-restructure {--dry-run : Raporu bas, hiçbir şey yazma} {--force : Gerçekten uygula} {--branch= : Şube kodu (varsayılan ERBAA)}';

    protected $description = 'Demo sınıflarını 9-12 × A/B (15 kişilik) yapısına dönüştürür, fazlasını bekleme listesine alır';

    public function handle(ClassStructure $structure, PlacementService $placement, MembershipWriter $writer): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->option('force')) {
            $this->error('Önce --dry-run ile raporu inceleyin; uygulamak için --force verin.');

            return self::INVALID;
        }

        $branch = Branch::query()->where('code', $this->option('branch') ?: 'ERBAA')->first() ?? Branch::query()->orderBy('id')->first();
        if (! $branch) {
            $this->error('Şube bulunamadı.');

            return self::FAILURE;
        }

        return app(BranchContext::class)->run($branch->id, function () use ($dry, $structure, $placement, $writer, $branch) {
            config(['kurs.silent_events' => true]);   // 200 veliye mesaj gitmesin
            $term = AcademicTerm::current();
            if (! $term) {
                $this->error('Güncel akademik dönem yok.');

                return self::FAILURE;
            }
            $this->line(($dry ? '<comment>[DRY-RUN — hiçbir şey yazılmayacak]</comment> ' : '<error>[UYGULANIYOR]</error> ')."Şube: {$branch->name} · Dönem: {$term->name}");

            DB::beginTransaction();
            try {
                $report = $this->restructure($term, $structure, $placement, $writer);
                if ($dry) {
                    DB::rollBack();
                    $writer->discardEvents();
                } else {
                    DB::commit();
                }
            } catch (Throwable $e) {
                DB::rollBack();
                $this->error('Durduruldu, hiçbir değişiklik yazılmadı: '.$e->getMessage());

                return self::FAILURE;
            }

            $this->printReport($report, $dry);

            return self::SUCCESS;
        });
    }

    private function restructure(AcademicTerm $term, ClassStructure $structure, PlacementService $placement, MembershipWriter $writer): array
    {
        $settings = $structure->settings();
        $levels = $settings['levels'];
        $perLevel = $settings['capacity'] * count($settings['sections']);
        $today = CarbonImmutable::today();
        $cs = new ChangeSet;
        $report = [];

        $demoTagId = DB::table('tags')->where('name', 'Demo')->value('id');
        if (! $demoTagId) {
            throw new \RuntimeException('"Demo" etiketi yok; dönüştürülecek demo verisi bulunamadı.');
        }
        $demoIds = DB::table('taggables')->where('tag_id', $demoTagId)->where('taggable_type', 'student')->pluck('taggable_id')->map(fn ($v) => (int) $v)->all();
        $oldGroupsBefore = DB::table('class_groups')->where('academic_term_id', $term->id)->whereNull('deleted_at')->where('is_active', true)->pluck('name', 'id');

        // (1) 8 sınıf
        $ensure = $structure->ensure($term);
        $report['structure'] = $ensure;

        // (2) Seviye eşleme: 8→9, Mezun→12, 9-12 aynı
        $students = DB::table('students')->whereIn('id', $demoIds)->whereNull('deleted_at')->whereIn('status', ['active', 'frozen'])
            ->orderBy('id')->get(['id', 'full_name', 'status', 'school_grade', 'field', 'birth_date', 'registered_on']);
        $gradeBefore = $students->countBy(fn ($s) => $s->school_grade ?? '—')->sortKeys()->all();
        $grade = [];
        $remapped = 0;
        foreach ($students as $s) {
            $g = ClassStructure::gradeOf($s->school_grade);
            $new = match (true) {
                $s->school_grade !== null && mb_strtolower(trim($s->school_grade)) === 'mezun' => 12,
                $g !== null && $g <= 9 => 9,
                $g !== null && $g >= 12 => 12,
                $g !== null => $g,
                default => null,
            };
            if ($new === null) {
                continue;
            }
            $grade[$s->id] = $new;
        }

        // Seviye dağılımını dengele: eksik seviyeyi (<30) en fazla fazlası olan seviyeden tamamla
        $byId = $students->keyBy('id');
        $balanced = [];
        for ($guard = 0; $guard < 500; $guard++) {
            $counts = array_fill_keys($levels, 0);
            foreach ($grade as $g) {
                $counts[$g]++;
            }
            $deficit = null;
            foreach ($levels as $l) {
                if ($counts[$l] < $perLevel && ($deficit === null || $counts[$l] < $counts[$deficit])) {
                    $deficit = $l;
                }
            }
            $donor = null;
            foreach ($levels as $l) {
                if ($counts[$l] > $perLevel && ($donor === null || $counts[$l] > $counts[$donor] || ($counts[$l] === $counts[$donor] && abs($l - $deficit) < abs($donor - $deficit)))) {
                    $donor = $l;
                }
            }
            if ($deficit === null || $donor === null) {
                break;
            }
            // Aşağı taşırken en genç, yukarı taşırken en yaşlı öğrenci (yaşa en uygun olan)
            $pool = array_keys(array_filter($grade, fn ($g) => $g === $donor));
            usort($pool, function ($a, $b) use ($byId, $donor, $deficit) {
                $ka = [$byId[$a]->birth_date ?? '0000-00-00', $a];
                $kb = [$byId[$b]->birth_date ?? '0000-00-00', $b];

                return $donor > $deficit ? $kb <=> $ka : $ka <=> $kb;
            });
            $pick = $pool[0];
            $grade[$pick] = $deficit;
            $balanced[] = ['id' => $pick, 'full_name' => $byId[$pick]->full_name, 'from' => $donor, 'to' => $deficit];
        }

        foreach ($grade as $id => $g) {
            $s = $byId[$id];
            $update = ['school_grade' => (string) $g];
            if ($s->field === 'LGS') {
                $update['field'] = 'TYT';
            }
            if ((string) $s->school_grade !== (string) $g || isset($update['field'])) {
                $cs->recordStudent($id, array_intersect_key(['school_grade' => $s->school_grade, 'field' => $s->field], $update), $update);
                DB::table('students')->where('id', $id)->update($update + ['updated_at' => now()]);
                $remapped++;
            }
        }
        $report['grades'] = ['before' => $gradeBefore, 'after' => array_count_values($grade), 'remapped' => $remapped, 'balanced' => $balanced];
        ksort($report['grades']['after']);

        // (3) Seviye başına en fazla 30 aktif: fazlası bekleme listesi + "Kayıt bekliyor"
        $attendance = DB::table('attendances')->whereIn('student_id', array_keys($grade))
            ->selectRaw("student_id, COUNT(*) total, SUM(status IN ('present','late')) ok")->groupBy('student_id')->get()->keyBy('student_id');
        $rate = fn (int $id) => isset($attendance[$id]) && $attendance[$id]->total > 0 ? $attendance[$id]->ok / $attendance[$id]->total : 1.0;
        $waitlisted = [];
        $activeIds = [];
        foreach ($levels as $l) {
            $ids = array_keys(array_filter($grade, fn ($g) => $g === $l));
            // Kalacak olanlar önce: yüksek devam, eski kayıt; bekleme listesine en düşük devam / en yeni kayıt
            usort($ids, fn ($a, $b) => [-$rate($a), $byId[$a]->registered_on ?? '9999', $a] <=> [-$rate($b), $byId[$b]->registered_on ?? '9999', $b]);
            $activeIds = array_merge($activeIds, array_slice($ids, 0, $perLevel));
            foreach (array_slice($ids, $perLevel) as $id) {
                $s = $byId[$id];
                $pct = (int) round($rate($id) * 100);
                $writer->move($id, $term->id, null, $today->toDateString(), 'Sınıf yapısı dönüşümü: seviye kapasitesi doldu', 'restructure', null, $cs);
                $writer->addWaitlist($id, $term->id, $l, null, 'restructure', "Seviye kapasitesi ({$perLevel}) doldu. Devam %{$pct}, kayıt ".($s->registered_on ?? '—').'.', null, $cs);
                $cs->recordStudent($id, ['status' => $s->status], ['status' => 'pending']);
                DB::table('students')->where('id', $id)->update(['status' => 'pending', 'updated_at' => now()]);
                $waitlisted[] = ['id' => $id, 'full_name' => $s->full_name, 'level' => $l, 'attendance' => $pct, 'registered_on' => $s->registered_on];
            }
        }
        $report['waitlisted'] = $waitlisted;

        // (4) Yerleştirme botu: A/B dağıtımı (eski demo sınıflarındaki üyelikler bu adımda kapanır)
        $run = $placement->apply($term, $levels, 'redistribute', null, null, $today->toDateString(), [
            'kind' => 'restructure', 'in_transaction' => true, 'change_set' => $cs, 'student_filter' => $activeIds,
            'source' => 'restructure', 'reason' => 'Sınıf yapısı dönüşümü (9-12 × A/B)', 'waitlist_source' => 'restructure', 'defer_snapshot' => true,
        ]);
        $report['placement'] = $run->summary;
        $groups = $structure->groups($term->id);
        $report['classes'] = $groups->map(function ($g) {
            $m = DB::table('class_group_student as cgs')->join('students as s', 's.id', '=', 'cgs.student_id')->where('cgs.class_group_id', $g->id)->whereNull('cgs.left_on')
                ->selectRaw("COUNT(*) c, SUM(s.gender='female') f, SUM(s.gender='male') m")->first();

            return ['name' => $g->name, 'capacity' => $g->capacity, 'count' => (int) $m->c, 'female' => (int) $m->f, 'male' => (int) $m->m];
        })->values()->all();
        $report['scores'] = collect($run->summary['levels'] ?? [])->map(fn ($l) => [$l['level'], $l['score_before'] ?? '—', $l['score_after']])->all();

        // (5) Eski sınıflar: kalan demo üyelikleri kapat, içinde demo dışı öğrenci kalmayanları pasife al (SİLİNMEZ)
        $structuredIds = $groups->pluck('id')->all();
        $oldIds = DB::table('class_groups')->where('academic_term_id', $term->id)->whereNull('deleted_at')->where('is_active', true)->whereNotIn('id', $structuredIds)->pluck('id')->all();
        $closedLeftovers = 0;
        foreach (DB::table('class_group_student')->whereIn('class_group_id', $oldIds ?: [0])->whereNull('left_on')->whereIn('student_id', $demoIds)->pluck('student_id')->unique() as $sid) {
            // Yalnız eski sınıftaki satırı kapat (yeni şube üyeliği korunur)
            $rows = DB::table('class_group_student')->whereIn('class_group_id', $oldIds)->where('student_id', $sid)->whereNull('left_on')->get(['id', 'left_on']);
            foreach ($rows as $r) {
                $cs->closed[$r->id] ??= $r->left_on;
                DB::table('class_group_student')->where('id', $r->id)->update(['left_on' => $today->toDateString(), 'updated_at' => now()]);
                $closedLeftovers++;
            }
        }
        $deactivated = $kept = [];
        foreach ($oldIds as $gid) {
            $nonDemo = DB::table('class_group_student')->where('class_group_id', $gid)->whereNull('left_on')->whereNotIn('student_id', $demoIds ?: [0])->count();
            if ($nonDemo > 0) {
                $kept[] = $oldGroupsBefore[$gid]." ({$nonDemo} demo dışı öğrenci)";
                continue;
            }
            $cs->groups[$gid] = ['is_active' => true];
            DB::table('class_groups')->where('id', $gid)->update(['is_active' => false, 'updated_at' => now()]);
            $deactivated[] = $oldGroupsBefore[$gid];
        }

        // (6) Pasife alınan sınıfların haftalık şablonları dün itibarıyla biter; gelecekteki (yoklaması alınmamış) oturumları iptal edilir
        $yesterday = $today->subDay()->toDateString();
        $gone = array_keys(array_filter($cs->groups, fn ($g) => $g['is_active'] === true));
        $schedules = DB::table('lesson_schedules')->whereIn('class_group_id', $gone ?: [0])->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', $yesterday))->get(['id', 'valid_until']);
        foreach ($schedules as $sc) {
            $cs->schedules[$sc->id] = $sc->valid_until;
        }
        if ($schedules->isNotEmpty()) {
            DB::table('lesson_schedules')->whereIn('id', $schedules->pluck('id'))->update(['valid_until' => $yesterday, 'updated_at' => now()]);
        }
        $sessions = DB::table('lesson_sessions')->whereIn('class_group_id', $gone ?: [0])->where('date', '>=', $today->toDateString())
            ->where('status', 'scheduled')->whereNull('attendance_taken_at')->get(['id', 'status', 'cancel_reason']);
        foreach ($sessions as $se) {
            $cs->sessions[$se->id] = ['status' => $se->status, 'cancel_reason' => $se->cancel_reason];
        }
        if ($sessions->isNotEmpty()) {
            DB::table('lesson_sessions')->whereIn('id', $sessions->pluck('id'))->update(['status' => 'cancelled', 'cancel_reason' => 'Sınıf yapısı değişti (eski demo sınıfı)', 'updated_at' => now()]);
        }
        $report['old_classes'] = ['deactivated' => $deactivated, 'kept' => $kept, 'closed_leftover_memberships' => $closedLeftovers, 'schedules_ended' => $schedules->count(), 'sessions_cancelled' => $sessions->count()];

        // (7) Geri alma görüntüsü + denetim
        $summary = $run->summary + ['restructure' => [
            'classes_created' => $ensure['created'], 'programs_created' => $ensure['programs_created'], 'grades_remapped' => $remapped, 'grade_balanced' => count($balanced),
            'waitlisted' => count($waitlisted), 'old_classes_deactivated' => $deactivated, 'schedules_ended' => $schedules->count(), 'sessions_cancelled' => $sessions->count(),
        ]];
        $run->forceFill(['summary' => $summary, 'snapshot' => $cs->toArray()])->save();
        $report['run_id'] = $run->id;
        Audit::log('placement.demo_restructured', sprintf('Demo sınıflarını 9-12 × A/B yapısına dönüştürdü: %d sınıf, %d öğrenci yerleşti, %d öğrenci bekleme listesinde, %d eski sınıf pasife alındı (işlem #%d).',
            count($report['classes']), count($activeIds), count($waitlisted), count($deactivated), $run->id));
        ActivityFeed::query()->create(['kind' => 'placement', 'message' => 'Sınıf yapısı 9-12 × A/B olarak yeniden düzenlendi', 'meta' => ['placement_run_id' => $run->id], 'occurred_at' => now()]);

        $report['active_total'] = count($activeIds);

        return $report;
    }

    private function printReport(array $r, bool $dry): void
    {
        $s = $r['structure'];
        $this->newLine();
        $this->info('1) Sınıf yapısı');
        $this->line('   Oluşturulacak sınıf: '.($s['created'] ? implode(', ', $s['created']) : '—'));
        $this->line('   Eşleştirilen mevcut sınıf: '.($s['adopted'] || $s['reactivated'] ? implode(', ', array_unique(array_merge($s['adopted'], $s['reactivated']))) : '—'));
        $this->line('   Oluşturulacak program: '.($s['programs_created'] ? implode(', ', $s['programs_created']) : '—'));

        $this->info('2) Seviye eşleme (yalnız Demo etiketli aktif öğrenciler)');
        $this->line('   Önce: '.collect($r['grades']['before'])->map(fn ($c, $g) => "{$g}: {$c}")->implode(' · '));
        $this->line('   Sonra: '.collect($r['grades']['after'])->map(fn ($c, $g) => "{$g}: {$c}")->implode(' · '));
        $this->line("   school_grade değişen öğrenci: {$r['grades']['remapped']} · dağılım dengesi için seviyesi kaydırılan: ".count($r['grades']['balanced']));
        if ($r['grades']['balanced']) {
            $this->line('   Kaydırmalar: '.collect($r['grades']['balanced'])->countBy(fn ($b) => "{$b['from']}→{$b['to']}")->map(fn ($c, $k) => "{$k}: {$c}")->implode(' · '));
        }

        $this->info('3) Bekleme listesi (seviye başına en fazla 30 aktif; durum "Kayıt bekliyor")');
        $this->line('   Toplam: '.count($r['waitlisted']).' · seviye bazında: '.collect($r['waitlisted'])->countBy('level')->sortKeys()->map(fn ($c, $l) => "{$l}: {$c}")->implode(' · '));
        $this->table(['Öğrenci', 'Seviye', 'Devam %', 'Kayıt'], collect($r['waitlisted'])->take(15)->map(fn ($w) => [$w['full_name'], $w['level'], $w['attendance'], $w['registered_on']])->all());
        if (count($r['waitlisted']) > 15) {
            $this->line('   … ve '.(count($r['waitlisted']) - 15).' öğrenci daha');
        }

        $this->info('4) Yerleştirme botu');
        $p = $r['placement'];
        $this->line("   Yerleşen: {$p['placed']} · taşınan: {$p['moved']} · bot bekleme listesi: {$p['waitlisted']} · aktif toplam: {$r['active_total']}");
        $this->table(['Sınıf', 'Mevcut', 'Kapasite', 'Kız', 'Erkek'], array_map(fn ($c) => [$c['name'], $c['count'], $c['capacity'], $c['female'], $c['male']], $r['classes']));
        $this->line('   Denge puanı (seviye: önce → sonra): '.collect($r['scores'])->map(fn ($x) => "{$x[0]}: {$x[1]} → {$x[2]}")->implode(' · '));

        $o = $r['old_classes'];
        $this->info('5-6) Eski demo sınıfları');
        $this->line('   Pasife alınan (silinmez): '.($o['deactivated'] ? implode(', ', $o['deactivated']) : '—'));
        if ($o['kept']) {
            $this->line('   Demo dışı öğrenci olduğu için aktif bırakılan: '.implode(', ', $o['kept']));
        }
        $this->line("   Ek kapatılan üyelik: {$o['closed_leftover_memberships']} · biten haftalık şablon: {$o['schedules_ended']} · iptal edilen gelecek oturum: {$o['sessions_cancelled']}");
        $this->line('   Yeni sınıflara ders programı ÜRETİLMEDİ (ders programı botunun işi).');

        $this->newLine();
        $dry
            ? $this->comment('DRY-RUN: tüm adımlar transaction içinde çalıştırıldı ve geri alındı; kalıcı değişiklik yok. Uygulamak için: php artisan kurs:demo-restructure --force')
            : $this->info("Uygulandı. Geri almak için Sınıflar ve yerleştirme ekranında işlem #{$r['run_id']} → Geri al.");
    }
}
