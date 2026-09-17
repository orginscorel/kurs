<?php

namespace App\Services\Portal;

use App\Models\ContactRequest;
use App\Models\Teacher;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Veli → öğretmen talepleri için YALNIZ uygulama içi bildirim (app_notifications + varsa push).
 * SMS / WhatsApp / e-posta GÖNDERİLMEZ. Bildirim hatası talebin kaydını asla bozmaz.
 */
class ContactRequestNotifier
{
    public const TYPE_NEW = 'contact_request';

    public const TYPE_ANSWER = 'contact_answer';

    public function __construct(private readonly NotificationService $notifications) {}

    /** Yeni talep: ilgili öğretmenin portal hesabına bildirim. */
    public function created(ContactRequest $row): int
    {
        return $this->safely(function () use ($row) {
            $teacherUserId = (int) (Teacher::query()->withoutGlobalScopes()->whereKey($row->teacher_id)->value('user_id') ?? 0);
            if (! $teacherUserId) {
                return 0;
            }
            $student = (string) (DB::table('students')->where('id', $row->student_id)->value('full_name') ?? '');
            $kind = ContactRequest::KINDS[$row->kind] ?? 'Talep';

            return $this->notifications->notify(
                $teacherUserId,
                self::TYPE_NEW,
                sprintf('Yeni veli talebi: %s', $kind),
                trim(sprintf('%s velisi: "%s"', $student ?: 'Öğrenci', mb_substr($row->subject, 0, 120))),
                '/ogretmen/talepler',
                ['contact_request_id' => $row->id, 'once' => 'contact_request:'.$row->id],
            );
        });
    }

    /** Öğretmen yanıtladı / kapattı: talebi açan veli hesabına bildirim. */
    public function responded(ContactRequest $row, ?string $teacherName = null): int
    {
        return $this->safely(function () use ($row, $teacherName) {
            if (! $row->user_id) {
                return 0;
            }
            $answered = $row->status === 'answered';
            $who = $teacherName ?: 'Öğretmen';

            return $this->notifications->notify(
                (int) $row->user_id,
                self::TYPE_ANSWER,
                $answered ? sprintf('%s talebinizi yanıtladı', $who) : sprintf('%s talebinizi kapattı', $who),
                $answered ? mb_substr((string) $row->response, 0, 300) : sprintf('"%s" konulu talep kapatıldı.', mb_substr($row->subject, 0, 120)),
                '/portal/ogretmenler',
                ['contact_request_id' => $row->id, 'status' => $row->status, 'once' => 'contact_answer:'.$row->id.':'.$row->status.':'.($row->responded_at?->timestamp ?? 0)],
            );
        });
    }

    private function safely(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }
}
