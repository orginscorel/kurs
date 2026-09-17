<?php

namespace App\Http\Controllers\Api\Discipline;

use App\Models\DisciplineBoardMeeting;
use App\Models\DisciplineDefense;
use App\Models\DisciplineIncident;
use App\Models\DisciplineSanction;
use App\Support\Discipline\DisciplineCatalog as C;

/** Disiplin kayıtlarının API gösterimi (liste ve ayrıntı ortak). */
final class DisciplinePresenter
{
    /** Türkçe doğrulama iletileri (kurulu dil dosyası yok). */
    public static function messages(): array
    {
        return [
            'required' => ':attribute zorunludur.',
            'required_if' => ':attribute zorunludur.',
            'date' => ':attribute geçerli bir tarih olmalı.',
            'date_format' => ':attribute geçerli bir tarih olmalı.',
            'integer' => ':attribute bir sayı olmalı.',
            'min' => ['string' => ':attribute en az :min karakter olmalı.', 'numeric' => ':attribute en az :min olmalı.', 'array' => 'En az :min :attribute seçin.', 'file' => ':attribute en az :min KB olmalı.'],
            'max' => ['string' => ':attribute en fazla :max karakter olabilir.', 'numeric' => ':attribute en fazla :max olabilir.', 'array' => ':attribute en fazla :max kayıt içerebilir.', 'file' => ':attribute en fazla :max KB olabilir.'],
            'in' => ':attribute için geçersiz seçim.',
            'exists' => ':attribute bulunamadı.',
            'array' => ':attribute listesi geçersiz.',
            'boolean' => ':attribute evet/hayır olmalı.',
            'string' => ':attribute metin olmalı.',
            'file' => 'Dosya yüklenemedi.',
            'mimes' => 'Yalnız fotoğraf (JPG/PNG/WEBP) ya da PDF yüklenebilir.',
            'after_or_equal' => ':attribute başlangıçtan önce olamaz.',
            'students.min' => 'En az bir öğrenci seçin.',
            'file.max' => 'Dosya en fazla 10 MB olabilir.',
        ];
    }

    public static function attributes(): array
    {
        return [
            'occurred_at' => 'Olay tarihi', 'location' => 'Yer', 'description' => 'Açıklama', 'students' => 'Öğrenciler',
            'students.*.student_id' => 'Öğrenci', 'students.*.behavior_id' => 'Davranış', 'students.*.role' => 'Rol',
            'severity' => 'Ciddiyet', 'status' => 'Durum', 'reason' => 'Gerekçe', 'due_on' => 'Son tarih', 'statement' => 'Savunma metni',
            'student_id' => 'Öğrenci', 'sanction_type_id' => 'Yaptırım', 'starts_on' => 'Başlangıç', 'days' => 'Gün sayısı',
            'title' => 'Başlık', 'scheduled_at' => 'Toplantı zamanı', 'members' => 'Üyeler', 'result' => 'Sonuç',
            'votes_for' => 'Kabul oyu', 'votes_against' => 'Ret oyu', 'votes_abstain' => 'Çekimser', 'name' => 'Ad', 'points' => 'Puan',
            'category' => 'Kategori', 'kind' => 'Tür', 'appealed_on' => 'İtiraz tarihi', 'via' => 'Bildirim yolu',
            'class_group_id' => 'Sınıf', 'subject_id' => 'Ders', 'teacher_id' => 'Öğretmen', 'witnesses' => 'Tanıklar',
            'students.*.note' => 'Öğrenci notu', 'quick_sanction_type_id' => 'Hemen verilecek yaptırım', 'note' => 'Not',
            'outcome' => 'Sonuç', 'result_note' => 'Karar notu', 'summary' => 'Özet', 'decision' => 'Karar', 'decision_note' => 'Karar notu',
            'appellant' => 'İtiraz eden', 'sanction_id' => 'Yaptırım', 'new_sanction_type_id' => 'Yeni yaptırım', 'incident_id' => 'Olay',
            'incident_ids' => 'Olaylar', 'members.*.user_id' => 'Üye', 'members.*.role' => 'Üye görevi', 'members.*.present' => 'Katılım',
            'submitted_at' => 'Teslim tarihi', 'duty_description' => 'Görev açıklaması', 'suggested_sanction' => 'Önerilen yaptırım',
            'is_active' => 'Etkin', 'sort_order' => 'Sıra', 'code' => 'Kod', 'type_id' => 'Yaptırım türü', 'file' => 'Dosya',
            'visible_to_portal' => 'Portalda görünsün', 'level' => 'Düzey', 'authority' => 'Karar yetkisi', 'expires_after_days' => 'Düşme süresi',
        ];
    }

    public static function incidentRow(DisciplineIncident $i): array
    {
        $participants = $i->relationLoaded('participants') ? $i->participants : collect();

        return [
            'id' => $i->id,
            'incident_no' => $i->incident_no,
            'kind' => $i->kind,
            'occurred_at' => $i->occurred_at?->toAtomString(),
            'location' => $i->location,
            'title' => $i->title,
            'severity' => $i->severity,
            'severity_label' => C::SEVERITIES[$i->severity] ?? $i->severity,
            'status' => $i->status,
            'status_label' => C::INCIDENT_STATUSES[$i->status] ?? $i->status,
            'outcome' => $i->outcome,
            'source' => $i->source,
            'reporter' => $i->relationLoaded('reporter') ? $i->reporter?->name : null,
            'teacher' => $i->relationLoaded('teacher') ? $i->teacher?->full_name : null,
            'subject' => $i->relationLoaded('subject') ? $i->subject?->name : null,
            'class_group' => $i->relationLoaded('classGroup') ? $i->classGroup?->name : null,
            'students' => $participants->where('role', 'involved')->values()->map(fn ($p) => [
                'id' => $p->student_id, 'full_name' => $p->student?->full_name, 'student_no' => $p->student?->student_no,
                'behavior' => $p->behavior?->name, 'points' => $p->penalty_points ?: $p->merit_points,
            ])->all(),
            'others_count' => $participants->where('role', '!=', 'involved')->count(),
            'sanctions_count' => $i->sanctions_count ?? null,
            'guardian_notified_at' => $i->guardian_notified_at?->toAtomString(),
            'created_at' => $i->created_at?->toAtomString(),
        ];
    }

    public static function sanction(DisciplineSanction $s): array
    {
        $type = $s->type;

        return [
            'id' => $s->id,
            'sanction_no' => $s->sanction_no,
            'incident_id' => $s->incident_id,
            'incident_no' => $s->relationLoaded('incident') ? $s->incident?->incident_no : null,
            'student' => $s->relationLoaded('student') && $s->student ? ['id' => $s->student->id, 'full_name' => $s->student->full_name, 'student_no' => $s->student->student_no] : null,
            'type' => $type ? ['id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'level' => $type->level, 'authority' => $type->authority,
                'is_suspension' => $type->is_suspension, 'tone' => $type->tone] : null,
            'status' => $s->status,
            'status_label' => C::SANCTION_STATUSES[$s->status] ?? $s->status,
            'decision_note' => $s->decision_note,
            'duty_description' => $s->duty_description,
            'starts_on' => $s->starts_on?->toDateString(),
            'ends_on' => $s->ends_on?->toDateString(),
            'days' => $s->days,
            'expires_on' => $s->expires_on?->toDateString(),
            'visible_to_portal' => $s->visible_to_portal,
            'decided_by' => $s->relationLoaded('decider') ? $s->decider?->name : null,
            'decided_at' => $s->decided_at?->toAtomString(),
            'board_meeting_id' => $s->board_meeting_id,
            'board_meeting_no' => $s->relationLoaded('meeting') ? $s->meeting?->meeting_no : null,
            'cancel_reason' => $s->cancel_reason,
            'guardian_notified_at' => $s->guardian_notified_at?->toAtomString(),
            'appeals' => $s->relationLoaded('appeals') ? $s->appeals->map(fn ($a) => [
                'id' => $a->id, 'appellant' => $a->appellant, 'appealed_on' => $a->appealed_on?->toDateString(), 'reason' => $a->reason,
                'status' => $a->status, 'status_label' => C::APPEAL_STATUSES[$a->status] ?? $a->status, 'result_note' => $a->result_note,
                'decided_at' => $a->decided_at?->toAtomString(), 'decided_by' => $a->decider?->name,
            ])->values()->all() : [],
        ];
    }

    public static function defense(DisciplineDefense $d): array
    {
        return [
            'id' => $d->id,
            'incident_id' => $d->incident_id,
            'incident_no' => $d->relationLoaded('incident') ? $d->incident?->incident_no : null,
            'student' => $d->relationLoaded('student') && $d->student ? ['id' => $d->student->id, 'full_name' => $d->student->full_name, 'student_no' => $d->student->student_no] : null,
            'status' => $d->status,
            'status_label' => C::DEFENSE_STATUSES[$d->status] ?? $d->status,
            'overdue' => $d->isOverdue(),
            'requested_at' => $d->requested_at?->toAtomString(),
            'requested_by' => $d->relationLoaded('requester') ? $d->requester?->name : null,
            'due_on' => $d->due_on?->toDateString(),
            'request_note' => $d->request_note,
            'statement' => $d->statement,
            'submitted_at' => $d->submitted_at?->toAtomString(),
            'submitted_via' => $d->submitted_via,
        ];
    }

    public static function meeting(DisciplineBoardMeeting $m): array
    {
        return [
            'id' => $m->id,
            'meeting_no' => $m->meeting_no,
            'title' => $m->title,
            'scheduled_at' => $m->scheduled_at?->toAtomString(),
            'location' => $m->location,
            'status' => $m->status,
            'status_label' => C::BOARD_STATUSES[$m->status] ?? $m->status,
            'members' => array_map(fn ($x) => $x + ['role_label' => C::BOARD_ROLES[$x['role']] ?? $x['role']], $m->members ?? []),
            'notes' => $m->notes,
            'held_at' => $m->held_at?->toAtomString(),
            'items_count' => $m->items_count ?? null,
            'pending_count' => $m->pending_count ?? null,
        ];
    }
}
