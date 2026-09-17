<?php

namespace App\Sync\Local;

use App\Models\CollectionNote;
use App\Services\Finance\CollectionService;

/** Yerel düğümde tahsilat takip notları (söz, görüşme, hatırlatma taslağı) komut olarak eşitlenir. Mesaj GÖNDERİLMEZ. */
class LocalCollectionService extends CollectionService
{
    public function add(array $data): CollectionNote
    {
        return app(LocalCommandRecorder::class)->run('collection_note.add', [
            'data' => array_intersect_key($data, array_flip([
                'student_id', 'kind', 'guardian_id', 'promised_date', 'promised_amount', 'responsible_user_id', 'body', 'channel',
            ])),
        ], fn () => parent::add($data));
    }

    public function setStatus(CollectionNote $note, string $status, ?int $responsibleId = null): CollectionNote
    {
        return app(LocalCommandRecorder::class)->run('collection_note.status', [
            'note' => $note->id, 'status' => $status, 'responsible_user_id' => $responsibleId,
        ], fn () => parent::setStatus($note, $status, $responsibleId));
    }

    public function saveReminderDrafts(array $studentIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $studentIds)));

        return app(LocalCommandRecorder::class)->run('collection_note.reminders', ['student_ids' => $ids],
            fn () => parent::saveReminderDrafts($ids),
            fn (array $args, array $captured, int $count) => $count > 0 ? [$args, $captured] : null);
    }
}
