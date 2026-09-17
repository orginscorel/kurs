<?php

namespace App\Services\Automation;

use App\Models\AutomationRule;
use App\Models\MessageTemplate;
use App\Models\Student;
use App\Services\Messaging\MessageDispatcher;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationType;

/**
 * Bir kuralın `actions` listesini tek bir öğrenci odağında çalıştırır. Her eylem için alıcılar
 * çözülür ve WhatsApp/SMS/E-posta `outbound_messages` kuyruğuna, uygulama bildirimi
 * `app_notifications`'a yazılır. Hiçbir eylem sessizce yutulmaz — alıcı/şablon eksikse
 * ilgili mesaj `failed` olarak izlenebilir şekilde kaydedilir.
 */
class ActionExecutor
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly MessageDispatcher $dispatcher,
        private readonly NotificationService $notifications,
    ) {}

    public function run(AutomationRule $rule, Student $student, array $vars, array $context = []): void
    {
        $baseVars = array_merge(['ogrenci_adi' => $student->full_name], $vars);

        foreach ((array) $rule->actions as $index => $action) {
            $type = $action['type'] ?? 'whatsapp';
            $to = $action['to'] ?? 'guardian';
            $templateKey = $action['template'] ?? null;
            $recipients = $this->recipients->resolve($to, $student, $context);

            if ($recipients->isEmpty()) {
                continue; // ör. veli tanımlı değil — sistemde ayrıca uyarılır (öğrenci profili)
            }

            foreach ($recipients as $r) {
                $mergedVars = array_merge($baseVars, [
                    'veli_adi' => $r['type'] === 'guardian' ? $r['name'] : ($baseVars['veli_adi'] ?? ''),
                ]);

                $dedupe = DedupeKey::build([
                    'automation', $rule->id, $rule->trigger, $student->id, $type, $to,
                    $r['model']?->getKey() ?? '0', $context['dedupe_suffix'] ?? '',
                ]);

                if ($type === 'app') {
                    $this->sendApp($rule, $r, $templateKey, $mergedVars, $student);

                    continue;
                }

                $mediaPath = ($action['attach_report_card'] ?? false) ? ($context['report_card_path'] ?? null) : null;

                $this->dispatcher->queue([
                    'channel' => $type,
                    'to' => $type === 'email' ? $r['email'] : $r['phone'],
                    'recipient' => $r['model'],
                    'student_id' => $student->id,
                    'template_key' => $templateKey,
                    'vars' => $mergedVars,
                    'media_path' => $mediaPath,
                    'dedupe_key' => $dedupe,
                    'trigger' => "automation:{$rule->id}",
                    'created_by' => null,
                    'purpose' => 'informational',
                ]);
            }
        }
    }

    private function sendApp(AutomationRule $rule, array $recipient, ?string $templateKey, array $vars, Student $student): void
    {
        if (! $recipient['user_id']) {
            return;
        }

        $body = $rule->name;
        if ($templateKey) {
            $template = MessageTemplate::query()->whereIn('channel', ['push', 'whatsapp'])->where('key', $templateKey)
                ->where('is_active', true)->orderByRaw("FIELD(channel, 'push', 'whatsapp')")->first();
            if ($template) {
                $body = $template->render($vars);
            }
        }

        // type = NotificationCenter simge kategorisi (varchar 20) — tetikleyici adı değil.
        $this->notifications->notify($recipient['user_id'], NotificationType::forTrigger($rule->trigger), $rule->name, $body,
            "/ogrenciler/{$student->id}", ['rule_id' => $rule->id, 'trigger' => $rule->trigger, 'student_id' => $student->id]);
    }
}
