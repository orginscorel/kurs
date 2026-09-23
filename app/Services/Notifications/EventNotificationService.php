<?php

namespace App\Services\Notifications;

use App\Jobs\SendOutboundMessage;
use App\Models\ClassGroup;
use App\Models\MessageTemplate;
use App\Models\NotificationBatch;
use App\Models\NotificationSetting;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Services\Automation\RecipientResolver;
use App\Services\Messaging\ProviderFactory;
use App\Support\BranchContext;
use App\Support\Notifications\NotificationCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Bildirim omurgası: bir olay için kitle bazlı taslaklar üretir (taslak), PDF önizler, onay sonrası gönderir.
 *
 * GÜVENLİK: Gerçek WhatsApp gönderimi YALNIZCA bağlı bir entegrasyon varken yapılır (mevcut SendOutboundMessage
 * işi + sağlayıcı). Entegrasyon yoksa mesajlar "simülasyon" olarak işaretlenir; HİÇBİR gerçek istek yapılmaz.
 */
class EventNotificationService
{
    public function __construct(
        private RecipientResolver $recipients,
        private ProviderFactory $providers,
    ) {}

    // ----------------------------------------------------------------- Şablonlar (olay×kitle)

    /** İlgili şablonu bulur; yoksa katalog varsayılanından oluşturur. */
    public function template(int $branchId, string $eventType, string $audience, string $channel = 'whatsapp'): MessageTemplate
    {
        $existing = MessageTemplate::query()
            ->where('branch_id', $branchId)->where('event_type', $eventType)
            ->where('audience', $audience)->where('channel', $channel)->first();
        if ($existing) {
            return $existing;
        }

        $def = NotificationCatalog::event($eventType)['templates'][$audience] ?? null;

        return MessageTemplate::query()->firstOrCreate(
            ['branch_id' => $branchId, 'key' => "{$eventType}.{$audience}", 'channel' => $channel],
            [
                'event_type' => $eventType,
                'audience' => $audience,
                'name' => $def['name'] ?? (NotificationCatalog::label($eventType).' ('.($audience).')'),
                'body' => $def['body'] ?? '{{ogrenci_adi}}',
                'is_active' => true,
            ],
        );
    }

    /** Bir olayın tüm kitleleri için şablonları döndürür (yoksa oluşturur) — ayar/şablon ekranı için. */
    public function templatesFor(int $branchId, string $eventType, string $channel = 'whatsapp'): Collection
    {
        return collect(NotificationCatalog::audiencesFor($eventType))
            ->map(fn ($a) => $this->template($branchId, $eventType, $a, $channel));
    }

    // ----------------------------------------------------------------- Ayarlar

    public function setting(int $branchId, string $eventType): NotificationSetting
    {
        return NotificationSetting::query()->firstOrCreate(
            ['branch_id' => $branchId, 'event_type' => $eventType],
            ['enabled' => true, 'require_approval' => true, 'channels' => ['whatsapp'], 'audiences' => NotificationCatalog::audiencesFor($eventType)],
        );
    }

    // ----------------------------------------------------------------- Taslak üretimi

    /**
     * @param array{student_ids?:array<int>, class_group_id?:?int, audiences?:?array<string>, teacher_id?:?int, title?:?string, vars?:array<string,mixed>, channel?:string} $input
     */
    public function buildDrafts(string $eventType, array $input, ?int $userId = null): NotificationBatch
    {
        $event = NotificationCatalog::event($eventType);
        abort_if(! $event, 422, 'Bilinmeyen bildirim olayı.');

        $branchId = app(BranchContext::class)->require();
        $setting = $this->setting($branchId, $eventType);
        abort_if(! $setting->enabled, 422, NotificationCatalog::label($eventType).' bildirimi kapalı.');

        $channel = $input['channel'] ?? 'whatsapp';
        $audiences = NotificationCatalog::audiencesFor($eventType, $input['audiences'] ?? $setting->audiences);
        abort_if(empty($audiences), 422, 'En az bir hedef kitle seçilmelidir.');

        $students = $this->students($input);
        abort_if($students->isEmpty(), 422, 'Bildirim için öğrenci bulunamadı.');

        $extraVars = $input['vars'] ?? [];
        $teacherId = $input['teacher_id'] ?? null;

        return DB::transaction(function () use ($eventType, $event, $branchId, $channel, $audiences, $students, $extraVars, $teacherId, $userId, $input) {
            $batch = NotificationBatch::query()->create([
                'branch_id' => $branchId,
                'event_type' => $eventType,
                'title' => $input['title'] ?? NotificationCatalog::label($eventType),
                'status' => 'draft',
                'audiences' => $audiences,
                'context' => ['student_ids' => $students->pluck('id')->all(), 'vars' => $extraVars, 'teacher_id' => $teacherId],
                'created_by' => $userId,
            ]);

            $rows = [];
            $perStudentAudiences = array_values(array_diff($audiences, ['admin']));

            foreach ($students as $student) {
                $vars = array_merge($this->studentVars($student), $extraVars);
                foreach ($perStudentAudiences as $audience) {
                    $tpl = $this->template($branchId, $eventType, $audience, $channel);
                    $body = $tpl->render($vars);
                    $to = NotificationCatalog::AUDIENCE_TO[$audience];
                    foreach ($this->recipients->resolve($to, $student, ['teacher_id' => $teacherId]) as $r) {
                        $rows[] = $this->draftRow($batch, $student, $audience, $channel, $tpl->key, $body, $r, $userId, $eventType);
                    }
                }
            }

            // Yönetici kitlesi: öğrenci başına değil, gönderim başına TEK özet (gürültüyü önler).
            if (in_array('admin', $audiences, true)) {
                $summary = sprintf('%s: %d öğrenci için bildirim hazırlandı (%s).',
                    NotificationCatalog::label($eventType), $students->count(), now()->format('d.m.Y H:i'));
                foreach ($this->recipients->resolve('admin', $students->first(), []) as $r) {
                    $rows[] = $this->draftRow($batch, null, 'admin', $channel, null, $summary, $r, $userId, $eventType);
                }
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                OutboundMessage::query()->insert($chunk);
            }
            $batch->update(['total' => count($rows)]);

            return $batch->fresh();
        });
    }

    /** @return array<string,mixed> */
    private function draftRow(NotificationBatch $batch, ?Student $student, string $audience, string $channel, ?string $templateKey, string $body, array $recipient, ?int $userId, string $eventType): array
    {
        return [
            'branch_id' => $batch->branch_id,
            'channel' => $channel,
            'to' => $recipient['phone'] ?? $recipient['email'] ?? '',
            'audience' => $audience,
            'recipient_type' => $recipient['model'] ? $recipient['model']->getMorphClass() : null,
            'recipient_id' => $recipient['model']?->getKey(),
            'student_id' => $student?->id,
            'template_key' => $templateKey,
            'body' => $body,
            'status' => 'draft',
            'batch_id' => $batch->id,
            'created_by' => $userId,
            'trigger' => 'notify:'.$eventType,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // ----------------------------------------------------------------- PDF önizleme

    /** Onay öncesi önizleme PDF'i üretir, batch.pdf_path'e yazar; dosyanın tam yolunu döndürür. */
    public function renderPdf(NotificationBatch $batch): string
    {
        $messages = $batch->messages()->orderBy('audience')->orderBy('student_id')->get()
            ->groupBy('audience');

        $pdf = Pdf::loadView('pdf.notifications.batch', [
            'batch' => $batch,
            'eventLabel' => NotificationCatalog::label($batch->event_type),
            'audienceLabels' => NotificationCatalog::AUDIENCES,
            'grouped' => $messages,
        ])->setPaper('a4');

        $relative = "notifications/batch-{$batch->id}.pdf";
        Storage::disk('local')->put($relative, $pdf->output());
        $batch->update(['pdf_path' => $relative]);

        return Storage::disk('local')->path($relative);
    }

    // ----------------------------------------------------------------- Onay + gönderim

    /**
     * Taslağı onaylar ve gönderir. Bağlı entegrasyon varsa gerçek kuyruk; yoksa simülasyon (gerçek istek YOK).
     */
    public function approve(NotificationBatch $batch, int $userId): NotificationBatch
    {
        abort_if($batch->status === 'sent', 422, 'Bu gönderim zaten tamamlanmış.');
        abort_if($batch->status === 'cancelled', 422, 'İptal edilmiş gönderim onaylanamaz.');

        $channel = 'whatsapp';
        $resolved = $this->providers->resolve($channel);
        $integration = $resolved['integration'] ?? null;
        $live = $integration && $integration->is_enabled && $integration->status === 'connected';

        $sent = 0;
        DB::transaction(function () use ($batch, $userId, $live, &$sent) {
            $batch->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now()]);

            $drafts = $batch->messages()->where('status', 'draft')->get();
            foreach ($drafts as $msg) {
                if (empty($msg->to)) {
                    $msg->forceFill(['status' => 'failed', 'error' => 'Alıcı adresi/numarası bulunamadı.'])->save();

                    continue;
                }
                if ($live) {
                    $msg->forceFill(['status' => 'queued'])->save();
                    SendOutboundMessage::dispatch($msg->id, $msg->branch_id);
                } else {
                    // Simülasyon: gerçek gönderim YOK. Demo/önizleme için "gönderildi" olarak işaretlenir.
                    $msg->forceFill(['status' => 'sent', 'provider' => 'simulation', 'sent_at' => now()])->save();
                }
                $sent++;
            }
            $batch->update(['status' => 'sent', 'sent' => $sent]);
        });

        return $batch->fresh();
    }

    // ----------------------------------------------------------------- Yardımcılar

    private function students(array $input): Collection
    {
        if (! empty($input['student_ids'])) {
            return Student::query()->whereIn('id', $input['student_ids'])->get();
        }
        if (! empty($input['class_group_id'])) {
            $group = ClassGroup::query()->find($input['class_group_id']);

            return $group ? $group->students()->get() : collect();
        }

        return collect();
    }

    /** @return array<string,mixed> */
    private function studentVars(Student $student): array
    {
        $guardian = $student->primaryGuardian();
        $classGroup = $student->currentClassGroups()->first();

        return [
            'ogrenci_adi' => $student->full_name,
            'okul_no' => $student->student_no,
            'veli_adi' => $guardian?->full_name ?? 'Velimiz',
            'sinif' => $classGroup?->name ?? '',
            'tarih' => now()->format('d.m.Y'),
        ];
    }
}
