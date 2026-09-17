<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Api\ApiController;
use App\Models\ClassGroup;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Services\Automation\RecipientResolver;
use App\Services\Messaging\MessageDispatcher;
use App\Support\BranchContext;
use App\Support\Sensitive;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** WhatsApp/SMS/E-posta gönderim geçmişi + tekli/toplu gönderim. */
class MessageController extends ApiController
{
    public function __construct(
        private readonly MessageDispatcher $dispatcher,
        private readonly RecipientResolver $recipients,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = OutboundMessage::query()
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->query('channel')))
            ->when($request->filled('recipient_type'), fn (Builder $q) => $q->where('recipient_type', $request->query('recipient_type')))
            ->when($request->filled('student_id'), fn (Builder $q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('q'), function (Builder $q) use ($request) {
                $term = '%'.$request->query('q').'%';
                $q->where(fn ($w) => $w->where('to', 'like', $term)->orWhere('body', 'like', $term));
            });
        $this->applyDateRange($query, $request, 'created_at');
        $this->applySort($query, $request, ['created_at' => 'created_at', 'status' => 'status', 'channel' => 'channel'], '-created_at');

        $canSeeFull = $request->user()->can('students.view_sensitive');

        $stats = OutboundMessage::query()
            ->when($request->filled('channel'), fn (Builder $q) => $q->where('channel', $request->query('channel')))
            ->selectRaw("count(*) total, sum(status in ('sent','delivered','read')) sent, sum(status='delivered' or status='read') delivered, sum(status='read') read_count, sum(status='failed') failed")
            ->first();

        return $this->paginated(
            $query->paginate($this->perPage($request, 30)),
            fn (OutboundMessage $m) => [
                'id' => $m->id, 'channel' => $m->channel, 'to' => $canSeeFull ? $m->to : Sensitive::maskPhone($m->to),
                'recipient_type' => $m->recipient_type, 'student_id' => $m->student_id, 'template_key' => $m->template_key,
                'subject' => $m->subject, 'body' => mb_substr($m->body, 0, 300), 'status' => $m->status,
                'status_label' => OutboundMessage::STATUSES[$m->status] ?? $m->status, 'attempts' => $m->attempts,
                'error' => $m->error, 'trigger' => $m->trigger, 'sent_at' => $m->sent_at, 'delivered_at' => $m->delivered_at,
                'read_at' => $m->read_at, 'created_at' => $m->created_at,
            ],
            ['stats' => [
                'total' => (int) $stats->total, 'sent' => (int) $stats->sent, 'delivered' => (int) $stats->delivered,
                'read' => (int) $stats->read_count, 'failed' => (int) $stats->failed,
            ]],
        );
    }

    public function retry(OutboundMessage $message): JsonResponse
    {
        abort_unless($message->status === 'failed', 422, 'Yalnızca başarısız mesajlar yeniden denenebilir.');

        $message->forceFill(['status' => 'queued', 'error' => null])->save();
        \App\Jobs\SendOutboundMessage::dispatch($message->id, $message->branch_id);

        return $this->ok('Mesaj yeniden kuyruğa alındı.');
    }

    /** Gönderim öncesi alıcı sayısı + izinsiz/numarasız sayısı. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validateTarget($request);
        $students = $this->resolveStudents($data);
        $recipients = $students->flatMap(fn (Student $s) => $this->recipients->resolve($data['recipient_type'], $s, []));

        $noAddress = 0;
        $noConsent = 0;
        foreach ($recipients as $r) {
            $address = $data['channel'] === 'email' ? $r['email'] : $r['phone'];
            if (! $address) {
                $noAddress++;

                continue;
            }
            if ($r['model'] && $this->dispatcher->consentDenied($r['model'], $data['channel'])) {
                $noConsent++;
            }
        }

        return response()->json([
            'recipient_count' => $recipients->count(),
            'no_address_count' => $noAddress,
            'no_consent_count' => $noConsent,
            'sendable_count' => max(0, $recipients->count() - $noAddress - $noConsent),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $this->validateTarget($request);
        $data['template_key'] = $request->validate(['template_key' => ['required', 'string', 'max:60']], ['template_key.required' => 'Bir mesaj şablonu seçin.'], ['template_key' => 'Mesaj şablonu'])['template_key'];
        $vars = (array) $request->input('vars', []);

        $students = $this->resolveStudents($data);
        $queued = 0;
        $failed = 0;

        foreach ($students as $student) {
            foreach ($this->recipients->resolve($data['recipient_type'], $student, []) as $r) {
                $message = $this->dispatcher->queue([
                    'channel' => $data['channel'],
                    'to' => $data['channel'] === 'email' ? $r['email'] : $r['phone'],
                    'recipient' => $r['model'],
                    'student_id' => $student->id,
                    'template_key' => $data['template_key'],
                    'vars' => array_merge(['ogrenci_adi' => $student->full_name, 'veli_adi' => $r['type'] === 'guardian' ? $r['name'] : ''], $vars),
                    'dedupe_key' => null,
                    'trigger' => 'manual',
                    'created_by' => $request->user()->id,
                    'purpose' => 'informational',
                ]);

                if ($message?->status === 'failed') {
                    $failed++;
                } else {
                    $queued++;
                }
            }
        }

        return response()->json(['message' => "{$queued} mesaj kuyruğa alındı.", 'queued' => $queued, 'failed' => $failed]);
    }

    private function validateTarget(Request $request): array
    {
        return $request->validate([
            'channel' => ['required', Rule::in(['whatsapp', 'sms', 'email'])],
            'recipient_type' => ['required', Rule::in(['student', 'guardian'])],
            'target' => ['required', Rule::in(['all', 'class_group', 'program', 'ids'])],
            'target_id' => ['nullable', 'integer', 'required_if:target,class_group,program'],
            'ids' => ['nullable', 'array', 'required_if:target,ids'],
            'ids.*' => ['integer'],
        ], [
            'target_id.required_if' => 'Mesajın gideceği sınıfı ya da programı seçin.',
            'ids.required_if' => 'En az bir öğrenci seçin.',
        ], [
            'channel' => 'Gönderim kanalı', 'recipient_type' => 'Alıcı', 'target' => 'Hedef öğrenciler',
            'target_id' => 'Sınıf / program', 'ids' => 'Öğrenciler',
        ]);
    }

    /** @return Collection<int, Student> */
    private function resolveStudents(array $data): Collection
    {
        $branchId = app(BranchContext::class)->require();

        return match ($data['target']) {
            'class_group' => $data['target_id'] ? ClassGroup::query()->findOrFail($data['target_id'])->activeStudents()->get() : new Collection,
            'program' => $data['target_id']
                ? Student::query()->whereIn('status', ['active', 'enrolled'])->whereHas('currentClassGroups', fn ($q) => $q->where('program_id', $data['target_id']))->get()
                : new Collection,
            'ids' => Student::query()->whereIn('id', $data['ids'] ?? [])->get(),
            default => Student::query()->whereIn('status', ['active', 'enrolled'])->get(),
        };
    }
}
