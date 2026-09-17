<?php

namespace App\Services\Communication;

use App\Models\Announcement;
use App\Models\ClassGroup;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Notifications\NotificationService;
use App\Support\BranchContext;
use App\Support\Sensitive;
use Illuminate\Support\Collection;

/**
 * Duyuru yayımlama: hedef kitleyi (öğrenci/sınıf/program/öğretmen/veli) kişilere çözer,
 * seçilen her kanalda (uygulama bildirimi/WhatsApp/e-posta/SMS) tek tek kuyruğa yazar.
 */
class AnnouncementService
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{title:string, body:string, audience:array{type:string,id?:int,students_only?:bool}, channels:list<string>}  $data
     */
    public function publish(array $data, ?User $author): Announcement
    {
        $branchId = app(BranchContext::class)->require();
        $recipients = $this->resolveAudience($data['audience']);

        $announcement = Announcement::query()->create([
            'branch_id' => $branchId,
            'title' => $data['title'],
            'body' => $data['body'],
            'audience' => $data['audience'],
            'channels' => $data['channels'],
            'published_at' => now(),
            'recipient_count' => $recipients->count(),
            'created_by' => $author?->id,
        ]);

        foreach ($data['channels'] as $channel) {
            foreach ($recipients as $recipient) {
                $this->send($announcement, $channel, $recipient);
            }
        }

        // Sınıf/program duyurusu velilerin portalında da görünür → yalnız uygulama içi bildirim (SMS/WhatsApp yok)
        if (in_array('app', $data['channels'], true) && self::visibleToGuardians($data['audience'])) {
            $guardianUserIds = $this->guardianUserIds($recipients);
            if ($guardianUserIds !== []) {
                $this->notifications->notify($guardianUserIds, 'announcement', $announcement->title, $announcement->body, '/portal/duyurular');
            }
        }

        return $announcement;
    }

    /** Sınıf/program hedefli ve "yalnız öğrencilere" işaretli değilse veliler de görür. */
    public static function visibleToGuardians(array $audience): bool
    {
        return in_array($audience['type'] ?? null, ['class_group', 'program'], true)
            && ! filter_var($audience['students_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<int> öğrenci alıcılarının (bildirim alan) velilerinin aktif portal hesapları */
    private function guardianUserIds(Collection $recipients): array
    {
        $studentIds = $recipients->where('kind', 'student')->map(fn ($r) => $r['model']->id)->values()->all();
        if ($studentIds === []) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('guardian_student as gs')
            ->join('guardians as g', 'g.id', '=', 'gs.guardian_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('gs.student_id', $studentIds)->where('gs.receives_notifications', true)
            ->whereNull('g.deleted_at')->where('u.is_active', true)
            ->distinct()->pluck('u.id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return Collection<int, array{model:object, kind:string, phone:?string, email:?string, user_id:?int, name:?string}> */
    private function resolveAudience(array $audience): Collection
    {
        $type = $audience['type'] ?? 'all_students';
        $id = $audience['id'] ?? null;

        return match ($type) {
            'class_group' => $id ? $this->fromStudents(ClassGroup::query()->findOrFail($id)->activeStudents()->get()) : collect(),
            'program' => $id ? $this->fromStudents(Student::query()->where('status', 'active')->whereHas('currentClassGroups', fn ($q) => $q->where('program_id', $id))->get()) : collect(),
            'teachers' => Teacher::query()->where('is_active', true)->get()->map(fn (Teacher $t) => [
                'model' => $t, 'kind' => 'teacher', 'user_id' => $t->user_id,
                'phone' => Sensitive::normalizePhone($t->whatsapp_phone ?: $t->phone), 'email' => $t->email, 'name' => $t->full_name,
            ]),
            'guardians' => Guardian::query()->get()->map(fn (Guardian $g) => [
                'model' => $g, 'kind' => 'guardian', 'user_id' => $g->user_id,
                'phone' => $g->messagingPhone(), 'email' => $g->email, 'name' => $g->full_name,
            ]),
            default => $this->fromStudents(Student::query()->whereIn('status', ['active', 'enrolled', 'frozen'])->get()),
        };
    }

    private function fromStudents(Collection $students): Collection
    {
        return $students->map(fn (Student $s) => [
            'model' => $s, 'kind' => 'student', 'user_id' => $s->user_id,
            'phone' => Sensitive::normalizePhone($s->whatsapp_phone ?: $s->phone), 'email' => $s->email, 'name' => $s->full_name,
        ]);
    }

    private function send(Announcement $announcement, string $channel, array $recipient): void
    {
        if ($channel === 'app') {
            if ($recipient['user_id']) {
                $this->notifications->notify($recipient['user_id'], 'announcement', $announcement->title, $announcement->body, null);
            }

            return;
        }

        $isWhatsApp = $channel === 'whatsapp';

        $this->dispatcher->queue([
            'channel' => $channel,
            'to' => $channel === 'email' ? $recipient['email'] : $recipient['phone'],
            'recipient' => $recipient['model'],
            'student_id' => $recipient['kind'] === 'student' ? $recipient['model']->id : null,
            'template_key' => $isWhatsApp ? 'announcement' : null,
            'vars' => ['baslik' => $announcement->title, 'metin' => $announcement->body],
            'body' => $isWhatsApp ? null : ($announcement->title."\n\n".$announcement->body),
            'dedupe_key' => "announcement:{$announcement->id}:{$recipient['kind']}:{$recipient['model']->getKey()}:{$channel}",
            'trigger' => "announcement:{$announcement->id}",
            'created_by' => $announcement->created_by,
            'purpose' => 'informational',
        ]);
    }
}
