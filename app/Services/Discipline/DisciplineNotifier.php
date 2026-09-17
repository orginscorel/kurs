<?php

namespace App\Services\Discipline;

use App\Models\DisciplineDefense;
use App\Models\DisciplineIncident;
use App\Models\DisciplineSanction;
use App\Models\MessageTemplate;
use App\Models\Student;
use App\Services\Automation\AutomationEngine;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\StaffRecipients;
use App\Support\Sensitive;
use Illuminate\Support\Facades\DB;

/**
 * Veli bildirimi: mesaj şablonundan TASLAK üretir (gönderim yok). Otomasyon tetikleyicisi
 * 'discipline.sanction_decided' atılır; ilgili kural migration ile PASİF gelir, yönetici açmadıkça mesaj gitmez.
 */
class DisciplineNotifier
{
    public const TEMPLATE_KEYS = [
        'sanction' => 'discipline.sanction.guardian',
        'defense' => 'discipline.defense.guardian',
        'positive' => 'discipline.positive.guardian',
    ];

    /**
     * @return array{kind:string, template_key:string, template_found:bool, text:string, guardians:list<array{name:string, phone:?string, relationship:?string}>}
     */
    public function draft(DisciplineIncident $incident, Student $student, string $kind, bool $sensitive, ?DisciplineSanction $sanction = null): array
    {
        $student->loadMissing('guardians');
        $guardian = $student->primaryGuardian();
        $key = self::TEMPLATE_KEYS[$kind];
        $template = MessageTemplate::query()->where('branch_id', $incident->branch_id)->where('key', $key)->where('is_active', true)->first()
            ?? MessageTemplate::query()->whereNull('branch_id')->where('key', $key)->first();

        $vars = $this->vars($incident, $student, $guardian?->full_name, $sanction);
        $text = $template ? $template->render($vars) : $this->fallback($kind, $vars);

        return [
            'kind' => $kind,
            'template_key' => $key,
            'template_found' => (bool) $template,
            'text' => $text,
            'guardians' => $student->guardians->map(fn ($g) => [
                'name' => $g->full_name,
                'relationship' => $g->pivot->relationship ?? null,
                'phone' => $sensitive ? $g->messagingPhone() : Sensitive::maskPhone($g->messagingPhone()),
                'is_primary' => (bool) $g->pivot->is_primary,
            ])->values()->all(),
        ];
    }

    /** @return array<string, string> */
    public function vars(DisciplineIncident $incident, Student $student, ?string $guardianName, ?DisciplineSanction $sanction = null): array
    {
        $behaviors = DB::table('discipline_incident_students as dis')->join('discipline_behaviors as b', 'b.id', '=', 'dis.behavior_id')
            ->where('dis.incident_id', $incident->id)->where('dis.student_id', $student->id)->pluck('b.name')->implode(', ');
        $defense = DisciplineDefense::query()->where('incident_id', $incident->id)->where('student_id', $student->id)->first();
        $duration = '';
        if ($sanction?->starts_on) {
            $duration = sprintf(' Uzaklaştırma süresi: %s – %s (%d gün).', $sanction->starts_on->format('d.m.Y'), $sanction->ends_on->format('d.m.Y'), $sanction->days);
        } elseif ($sanction?->duty_description) {
            $duration = ' Görev: '.$sanction->duty_description.'.';
        }

        return [
            'veli_adi' => $guardianName ?: 'Velimiz',
            'ogrenci_adi' => $student->full_name,
            'olay_tarihi' => $incident->occurred_at->format('d.m.Y'),
            'olay_no' => $incident->incident_no,
            'davranis' => $behaviors ?: ($incident->title ?? 'disiplin olayı'),
            'yaptirim' => $sanction?->type?->name ?? '',
            'sure_bilgisi' => $duration,
            'son_tarih' => $defense?->due_on?->format('d.m.Y') ?? '',
        ];
    }

    private function fallback(string $kind, array $v): string
    {
        return match ($kind) {
            'sanction' => "Sayın {$v['veli_adi']}, öğrencimiz {$v['ogrenci_adi']} ile ilgili {$v['olay_tarihi']} tarihli olay değerlendirilmiş ve \"{$v['yaptirim']}\" kararı verilmiştir.{$v['sure_bilgisi']}",
            'defense' => "Sayın {$v['veli_adi']}, öğrencimiz {$v['ogrenci_adi']} ile ilgili {$v['olay_tarihi']} tarihli olay nedeniyle {$v['son_tarih']} tarihine kadar yazılı savunması istenmiştir.",
            default => "Sayın {$v['veli_adi']}, öğrencimiz {$v['ogrenci_adi']} {$v['olay_tarihi']} tarihinde \"{$v['davranis']}\" ile takdirimizi kazanmıştır.",
        };
    }

    /** Otomasyon motoruna olay bildirir; kural pasifse hiçbir şey gönderilmez. */
    public function fireSanctionDecided(?DisciplineSanction $sanction): void
    {
        if (! $sanction || ! $sanction->student || ! $sanction->visible_to_portal) {
            return;
        }
        $student = $sanction->student;
        $vars = $this->vars($sanction->incident, $student, $student->primaryGuardian()?->full_name, $sanction);
        unset($vars['veli_adi']); // alıcıya göre motor doldurur
        AutomationEngine::fire('discipline.sanction_decided', $student, $vars, [
            'branch_id' => $sanction->branch_id,
            'dedupe_suffix' => 'discipline_sanction:'.$sanction->id,
        ]);
    }

    /** Öğretmen portalından gelen olay bildirimi: karar yetkililerine uygulama içi bildirim (mesaj değil). */
    public function notifyStaffOfReport(DisciplineIncident $incident): void
    {
        if (config('kurs.silent_events')) {
            return;
        }
        $ids = StaffRecipients::withPermission((int) $incident->branch_id, 'discipline.decide');
        if ($ids === []) {
            return;
        }
        $names = $incident->participants()->join('students', 'students.id', '=', 'discipline_incident_students.student_id')->limit(3)->pluck('students.full_name')->implode(', ');
        $by = $incident->reporter?->name ?? 'Öğretmen';
        app(NotificationService::class)->notify($ids, 'warning', "Yeni disiplin bildirimi: {$names}", "{$by} bir disiplin olayı bildirdi ({$incident->incident_no}).",
            "/disiplin/olaylar/{$incident->id}", ['once' => 'discipline_report:'.$incident->id]);
    }
}
