<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\CollectionNote;
use App\Models\Guardian;
use App\Models\MessageTemplate;
use App\Models\Student;
use App\Support\InstitutionFormat;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Tahsilat takibi: ödeme sözü, görüşme notu, sorumlu kişi ve hatırlatma TASLAĞI.
 * Taslak yalnız metin olarak kaydedilir; mesaj kuyruğuna yazılmaz, GÖNDERİLMEZ, otomasyon açılmaz.
 */
class CollectionService
{
    /** @param array{student_id:int, kind:string, guardian_id?:?int, promised_date?:?string, promised_amount?:?string, responsible_user_id?:?int, body?:?string} $data */
    public function add(array $data): CollectionNote
    {
        if (! isset(CollectionNote::KINDS[$data['kind']])) {
            throw new BusinessRuleException('Geçersiz not türü.', 'invalid_kind');
        }
        $student = Student::query()->findOrFail($data['student_id']);
        if ($data['kind'] === 'promise') {
            if (empty($data['promised_date'])) {
                throw new BusinessRuleException('Ödeme sözü için tarih girin.', 'promise_date_required');
            }
            if (CarbonImmutable::parse($data['promised_date'])->lt(CarbonImmutable::today()->subDays(30))) {
                throw new BusinessRuleException('Söz tarihi 30 günden eski olamaz.', 'promise_date_invalid');
            }
        } elseif (mb_strlen(trim($data['body'] ?? '')) < 3) {
            throw new BusinessRuleException('Not metnini girin.', 'body_required');
        }
        if (! empty($data['guardian_id']) && ! $student->guardians()->whereKey($data['guardian_id'])->exists()) {
            throw new BusinessRuleException('Seçilen veli bu öğrenciye bağlı değil.', 'guardian_mismatch');
        }

        $note = CollectionNote::query()->create([
            'student_id' => $student->id,
            'guardian_id' => $data['guardian_id'] ?? null,
            'kind' => $data['kind'],
            'promised_date' => $data['promised_date'] ?? null,
            'promised_amount' => isset($data['promised_amount']) && $data['promised_amount'] !== '' ? Money::of($data['promised_amount']) : null,
            'responsible_user_id' => $data['responsible_user_id'] ?? Auth::id(),
            'status' => $data['kind'] === 'note' ? 'done' : 'open',
            'body' => isset($data['body']) ? mb_substr(trim((string) $data['body']), 0, 2000) : null,
            'channel' => $data['channel'] ?? null,
            'created_by' => Auth::id(),
        ]);
        FinanceAudit::log('collection.'.$data['kind'].'_added', sprintf('%s için %s ekledi%s.', $student->full_name, mb_strtolower(CollectionNote::KINDS[$data['kind']]),
            $note->promised_date ? ' ('.$note->promised_date->format('d.m.Y').($note->promised_amount ? ', '.Money::format($note->promised_amount).' TL' : '').')' : ''), $note);

        return $note;
    }

    public function setStatus(CollectionNote $note, string $status, ?int $responsibleId = null): CollectionNote
    {
        if (! isset(CollectionNote::STATUSES[$status])) {
            throw new BusinessRuleException('Geçersiz durum.', 'invalid_status');
        }
        $note->status = $status;
        if ($responsibleId !== null) {
            $note->responsible_user_id = $responsibleId;
        }
        $note->save();
        FinanceAudit::log('collection.status_changed', sprintf('Tahsilat takip kaydının durumunu "%s" yaptı.', CollectionNote::STATUSES[$status]), $note);

        return $note;
    }

    /**
     * Gecikmiş taksit hatırlatma metni (mevcut "payment.overdue" şablonuyla). GÖNDERİLMEZ.
     *
     * @return array{student_id:int, guardian_id:?int, guardian:?string, phone:?string, body:string, overdue:string, count:int}
     */
    public function reminderPreview(Student $student): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $rows = DB::table('installments')->where('student_id', $student->id)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->where('due_date', '<', $today)->orderBy('due_date')->get(['due_date', DB::raw('amount - paid_amount AS remaining')]);
        $overdue = $rows->reduce(fn ($s, $r) => bcadd($s, (string) $r->remaining, 2), '0.00');
        /** @var Guardian|null $guardian */
        $guardian = $student->guardians()->orderByDesc('guardian_student.is_financially_responsible')->orderByDesc('guardian_student.is_primary')->first();
        $template = MessageTemplate::query()->where('key', 'payment.overdue')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $student->branch_id))->orderByDesc('branch_id')->first();
        $vars = [
            'veli_adi' => $guardian ? trim($guardian->first_name.' '.$guardian->last_name) : 'Velimiz',
            'ogrenci_adi' => $student->full_name,
            'vade_tarihi' => $rows->first() ? CarbonImmutable::parse($rows->first()->due_date)->format('d.m.Y') : '',
            'tutar' => Money::format($overdue),
            'gecikme_gun' => $rows->first() ? (string) CarbonImmutable::parse($rows->first()->due_date)->diffInDays(CarbonImmutable::today()) : '0',
            'kurum_adi' => Settings::get('institution.name'),
        ];
        $body = $template
            ? $template->render($vars)
            : sprintf("Sayın %s,\n\n%s için %s TL tutarında vadesi geçmiş ödemeniz bulunmaktadır. Bilginize sunarız.\n\n%s", $vars['veli_adi'], $vars['ogrenci_adi'], $vars['tutar'], $vars['kurum_adi']);

        return [
            'student_id' => $student->id, 'guardian_id' => $guardian?->id, 'guardian' => $guardian ? $vars['veli_adi'] : null,
            'phone' => $guardian?->whatsapp_phone ?: $guardian?->phone, 'body' => $body, 'overdue' => $overdue, 'count' => $rows->count(),
        ];
    }

    /**
     * Seçilen öğrenciler için hatırlatma taslaklarını not olarak kaydeder (gönderim yok).
     *
     * @param list<int> $studentIds
     */
    public function saveReminderDrafts(array $studentIds): int
    {
        $count = 0;
        foreach (Student::query()->whereIn('id', array_slice(array_unique($studentIds), 0, 200))->get() as $student) {
            $p = $this->reminderPreview($student);
            if (! Money::isPositive($p['overdue'])) {
                continue;
            }
            CollectionNote::query()->create([
                'student_id' => $student->id, 'guardian_id' => $p['guardian_id'], 'kind' => 'reminder', 'status' => 'open',
                'body' => $p['body'], 'channel' => 'whatsapp', 'responsible_user_id' => Auth::id(), 'created_by' => Auth::id(),
            ]);
            $count++;
        }
        if ($count) {
            FinanceAudit::log('collection.reminder_drafts', "{$count} öğrenci için gecikme hatırlatma taslağı hazırladı (gönderilmedi).");
        }

        return $count;
    }
}
