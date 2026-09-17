<?php

namespace App\Services\Students;

use App\Models\Student;
use App\Models\StudentRiskScore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Akıllı öğrenci analizi ve risk puanı. Kural tabanlıdır (açıklanabilir); AI entegrasyonu
 * ileride bu çıktıyı yorumlayabilir ama karar insanda kalır.
 *
 * Risk (0-100) = devamsızlık 30 + net düşüşü 25 + ödev tamamlama 20 + rehberlik aralığı 15 + ödeme gecikmesi 10
 * + disiplin (dönem net puanı, en fazla 10; toplam 100 ile sınırlı)
 */
class StudentInsights
{
    public function riskFor(Student $student, bool $refresh = false): StudentRiskScore
    {
        $existing = $student->riskScore;
        if (! $refresh && $existing && $existing->calculated_at->gt(now()->subHours(12))) {
            return $existing;
        }

        [$factors, $insights] = $this->analyse($student);
        $score = (int) round(collect($factors)->sum(fn ($f) => $f['points']));
        $level = $score >= 55 ? 'high' : ($score >= 30 ? 'medium' : 'low');

        return StudentRiskScore::query()->updateOrCreate(
            ['student_id' => $student->id],
            ['score' => min(100, $score), 'level' => $level, 'factors' => $factors, 'insights' => $insights, 'calculated_at' => now()],
        );
    }

    /** @return array{0: list<array>, 1: list<array{tone:string, text:string, kind:string}>} */
    public function analyse(Student $student): array
    {
        $factors = [];
        $insights = [];
        $since = CarbonImmutable::today()->subDays(30);

        // 1) Devamsızlık (son 30 gün, ders bazlı)
        $att = DB::table('attendances')->where('student_id', $student->id)->where('date', '>=', $since->toDateString())
            ->selectRaw("COUNT(*) AS total, SUM(status = 'absent') AS absent, SUM(status = 'late') AS late")->first();
        $absentRate = $att->total ? $att->absent / $att->total : 0;
        $factors[] = ['key' => 'attendance', 'label' => 'Devamsızlık (30 gün)', 'value' => $att->total ? round($absentRate * 100).'%' : '—',
            'points' => min(30, $absentRate * 150), 'weight' => 30];
        if ($att->absent >= 3) {
            $insights[] = ['kind' => 'attendance', 'tone' => $att->absent >= 6 ? 'danger' : 'warning', 'text' => "Son 30 günde {$att->absent} derse katılmadı".($att->late ? ", {$att->late} kez geç kaldı." : '.')];
        }

        // 2) Net değişimi: son 3 sınav ortalaması − önceki 3 sınav ortalaması (aynı sınav türü)
        $results = DB::table('exam_results as r')->join('exams as e', 'e.id', '=', 'r.exam_id')->join('exam_types as t', 't.id', '=', 'e.exam_type_id')
            ->where('r.student_id', $student->id)->where('e.status', 'results_published')->whereNull('e.deleted_at')
            ->orderByDesc('e.exam_date')->limit(12)->get(['r.id', 'r.net', 'e.exam_date', 't.code']);
        $mainType = $results->countBy('code')->sortDesc()->keys()->first();
        $series = $results->where('code', $mainType)->pluck('net')->map(fn ($n) => (float) $n)->values();
        $netChange = null;
        if ($series->count() >= 4) {
            $recent = $series->take(3)->avg();
            $previous = $series->slice(3, 3)->avg();
            $netChange = round($recent - $previous, 1);
            $factors[] = ['key' => 'net_change', 'label' => "Net değişimi ({$mainType})", 'value' => ($netChange > 0 ? '+' : '').$netChange,
                'points' => $netChange < 0 ? min(25, abs($netChange) * 2.5) : 0, 'weight' => 25];
        } else {
            $factors[] = ['key' => 'net_change', 'label' => 'Net değişimi', 'value' => '—', 'points' => 0, 'weight' => 25];
        }

        // Ders bazında art arda düşüş (son 3 sınav)
        $lastIds = $results->where('code', $mainType)->take(3)->pluck('id');
        if ($lastIds->count() === 3) {
            $bySection = DB::table('exam_result_sections as s')->join('exam_sections as es', 'es.id', '=', 's.exam_section_id')
                ->join('exam_results as r', 'r.id', '=', 's.exam_result_id')->join('exams as e', 'e.id', '=', 'r.exam_id')
                ->whereIn('s.exam_result_id', $lastIds)->orderBy('e.exam_date')
                ->get(['es.name', 's.net', 'e.exam_date'])->groupBy('name');
            foreach ($bySection as $name => $rows) {
                $nets = $rows->pluck('net')->map(fn ($n) => (float) $n)->values();
                if ($nets->count() === 3 && $nets[0] > $nets[1] && $nets[1] > $nets[2] && ($nets[0] - $nets[2]) >= 2) {
                    $insights[] = ['kind' => 'exam', 'tone' => 'warning', 'text' => "{$name} netlerinde son 3 sınavdır düşüş var (".implode(' → ', $nets->map(fn ($n) => number_format($n, 2, ',', ''))->all()).').'];
                }
            }
        }
        if ($netChange !== null && $netChange >= 5) {
            $insights[] = ['kind' => 'exam', 'tone' => 'success', 'text' => "Son 3 {$mainType} sınavında ortalama +{$netChange} net yükseliş."];
        }

        // Konu başarısı (en az 4 soru sorulmuş konular)
        $weak = DB::table('student_topic_stats as st')->join('topics as tp', 'tp.id', '=', 'st.topic_id')->join('subjects as sb', 'sb.id', '=', 'tp.subject_id')
            ->where('st.student_id', $student->id)->where('st.asked', '>=', 4)
            ->selectRaw('sb.name AS subject, tp.name AS topic, ROUND(st.correct / st.asked * 100) AS rate')
            ->orderBy('rate')->limit(2)->get();
        foreach ($weak as $w) {
            if ($w->rate < 50) {
                $insights[] = ['kind' => 'topic', 'tone' => 'warning', 'text' => "Sınavlarda {$w->subject} – {$w->topic} konusunda başarı %{$w->rate} seviyesinde."];
            }
        }

        // 3) Ödev tamamlama (son 60 gün, teslim tarihi geçmiş)
        $hw = DB::table('homework_submissions as hs')->join('homework as h', 'h.id', '=', 'hs.homework_id')
            ->where('hs.student_id', $student->id)->where('h.due_at', '<', now())->where('h.due_at', '>=', now()->subDays(60))->whereNull('h.deleted_at')
            ->selectRaw("COUNT(*) AS total, SUM(hs.status IN ('submitted','late')) AS done")->first();
        $completion = $hw->total ? $hw->done / $hw->total : null;
        $factors[] = ['key' => 'homework', 'label' => 'Ödev tamamlama', 'value' => $completion === null ? '—' : '%'.round($completion * 100),
            'points' => $completion === null ? 0 : max(0, (0.9 - $completion)) * 22, 'weight' => 20];
        if ($completion !== null && $completion < 0.6 && $hw->total >= 3) {
            $insights[] = ['kind' => 'homework', 'tone' => 'warning', 'text' => sprintf('Son 60 günde ödevlerin %%%d\'ini tamamladı (%d/%d).', round($completion * 100), $hw->done, $hw->total)];
        }

        // 4) Son rehberlik görüşmesi
        $lastMeeting = DB::table('guidance_meetings')->where('student_id', $student->id)->whereNull('deleted_at')->max('met_at');
        $days = $lastMeeting ? (int) CarbonImmutable::parse($lastMeeting)->diffInDays(now()) : null;
        $factors[] = ['key' => 'guidance', 'label' => 'Son rehberlik', 'value' => $days === null ? 'Hiç' : "{$days} gün önce",
            'points' => $days === null ? 10 : min(15, max(0, ($days - 21) * 0.4)), 'weight' => 15];
        if ($days === null || $days > 45) {
            $insights[] = ['kind' => 'guidance', 'tone' => 'info', 'text' => $days === null ? 'Henüz rehberlik görüşmesi yapılmadı.' : "Son rehberlik görüşmesi {$days} gün önce."];
        }

        // 5) Ödeme gecikmesi
        $overdue = DB::table('installments')->where('student_id', $student->id)->where('status', 'overdue')
            ->selectRaw('COUNT(*) AS c, MIN(due_date) AS oldest')->first();
        $overdueDays = $overdue->oldest ? (int) CarbonImmutable::parse($overdue->oldest)->diffInDays(now()) : 0;
        $factors[] = ['key' => 'payment', 'label' => 'Ödeme gecikmesi', 'value' => $overdue->c ? "{$overdue->c} taksit, {$overdueDays} gün" : 'Yok',
            'points' => $overdue->c ? min(10, 3 + $overdueDays * 0.2) : 0, 'weight' => 10, 'finance' => true];

        // 6) Disiplin (dönemlik net puan; tablolar yoksa atlanır)
        if (\App\Services\Discipline\DisciplineStanding::ready()) {
            try {
                $settings = \App\Services\Discipline\DisciplineSettings::all($student->branch_id ? (int) $student->branch_id : null);
                $range = \App\Services\Discipline\DisciplineStanding::termRange($student->branch_id ? (int) $student->branch_id : null);
                $d = app(\App\Services\Discipline\DisciplineStanding::class)->forStudents([$student->id], $range, $settings)[$student->id] ?? null;
                $net = $d['net'] ?? 0;
                $factors[] = ['key' => 'discipline', 'label' => 'Disiplin (dönem)', 'value' => $d ? "{$net} puan · {$d['level_label']}" : 'Temiz',
                    'points' => \App\Services\Discipline\DisciplineRules::riskPoints($net, $settings), 'weight' => 10];
                if ($d && in_array($d['level'], ['warning', 'critical'], true)) {
                    $insights[] = ['kind' => 'discipline', 'tone' => $d['level'] === 'critical' ? 'danger' : 'warning',
                        'text' => "Bu dönem {$d['incidents']} disiplin olayı, net {$net} ceza puanı ({$d['level_label']} seviyesi)."];
                } elseif ($d && $d['positives'] >= 2) {
                    $insights[] = ['kind' => 'discipline', 'tone' => 'success', 'text' => "Bu dönem {$d['positives']} takdir/teşekkür kaydı var."];
                }
            } catch (\Throwable $e) {
                report($e); // disiplin verisi okunamazsa risk hesabı yine tamamlanır
            }
        }

        return [$factors, $insights];
    }
}
