<?php

namespace App\Http\Controllers\Api\Communication;

use App\Http\Controllers\Api\ApiController;
use App\Models\Announcement;
use App\Services\Communication\AnnouncementService;
use App\Services\Communication\CommunicationAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Duyurular: hedef kitle + kanal seçimiyle yayımlanır, alıcı sayısı gösterilir. */
class AnnouncementController extends ApiController
{
    public function __construct(private readonly AnnouncementService $service) {}

    public function index(Request $request): JsonResponse
    {
        $query = Announcement::query()->with('author:id,name')->orderByDesc('id');

        return $this->paginated(
            $query->paginate($this->perPage($request, 20)),
            fn (Announcement $a) => [
                'id' => $a->id, 'title' => $a->title, 'body' => mb_substr($a->body, 0, 240),
                'audience' => $a->audience, 'channels' => $a->channels, 'recipient_count' => $a->recipient_count,
                'author' => $a->author?->name, 'published_at' => $a->published_at,
            ],
        );
    }

    public function show(Announcement $announcement): JsonResponse
    {
        $sent = \App\Models\OutboundMessage::query()->where('trigger', "announcement:{$announcement->id}")
            ->selectRaw("count(*) total, sum(status in ('sent','delivered','read')) sent, sum(status='failed') failed")->first();

        return response()->json(['data' => $announcement->load('author:id,name'), 'delivery' => [
            'total' => (int) $sent->total, 'sent' => (int) $sent->sent, 'failed' => (int) $sent->failed,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:4000'],
            'audience' => ['required', 'array'],
            'audience.type' => ['required', Rule::in(['all_students', 'class_group', 'program', 'teachers', 'guardians'])],
            'audience.id' => ['nullable', 'integer', 'required_if:audience.type,class_group,program'],
            // Sınıf/program duyurusu varsayılan olarak o öğrencilerin velilerine de görünür; true ise yalnız öğrenciler
            'audience.students_only' => ['sometimes', 'boolean'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => [Rule::in(['app', 'whatsapp', 'email', 'sms'])],
        ], [
            'audience.id.required_if' => 'Duyurunun gideceği sınıfı ya da programı seçin.',
            'channels.required' => 'En az bir gönderim kanalı seçin.',
            'channels.min' => 'En az bir gönderim kanalı seçin.',
        ]);

        $announcement = $this->service->publish($data, $request->user());
        CommunicationAudit::log('communication.announcement.publish', "\"{$announcement->title}\" duyurusunu {$announcement->recipient_count} alıcıya yayımladı.", $announcement);

        return response()->json(['message' => 'Duyuru yayımlandı.', 'id' => $announcement->id, 'recipient_count' => $announcement->recipient_count], 201);
    }
}
