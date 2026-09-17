<?php

namespace App\Sync\Local;

use App\Services\Finance\PromissoryNoteService;
use App\Sync\RowCodec;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yerel düğümde senet hazırlama (numara verme) ve basım kaydı komut olarak eşitlenir.
 * Yeni senetler taksit bazında (taksit uuid'i => [senet uuid, senet no]) gönderilir: sunucu basılı numarayı korur;
 * sunucuda o taksidin geçerli senedi zaten varsa sunucudaki kalır ve mutabakata düşer.
 */
class LocalPromissoryNoteService extends PromissoryNoteService
{
    public function prepare(Collection $installments): Collection
    {
        $ids = $installments->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        return app(LocalCommandRecorder::class)->run('promissory_note.prepare', ['installments' => $ids],
            fn () => parent::prepare($installments),
            function (array $args, array $captured, Collection $notes) {
                $new = $captured['uuids']['promissory_notes'] ?? [];
                $map = [];
                foreach ($notes as $note) {
                    if (in_array($note->uuid, $new, true)) {
                        $map[app(RowCodec::class)->uuidFor('installments', (int) $note->installment_id)] = [$note->uuid, $note->note_no];
                    }
                }
                unset($captured['uuids']['promissory_notes'], $captured['numbers']['promissory_note']);
                if ($map === [] && ($captured['touched']['promissory_notes'] ?? []) === []) {
                    return null;   // hiçbir senet oluşmadı/değişmedi
                }
                $args['notes'] = $map;

                return [$args, $captured];
            });
    }

    public function pdf(Collection $notes, int $perPage = 3, bool $copyMark = true, bool $inline = false): Response
    {
        $ids = $notes->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        if ($ids === []) {
            return parent::pdf($notes, $perPage, $copyMark, $inline);   // açık hata
        }

        return app(LocalCommandRecorder::class)->run('promissory_note.print', ['notes' => $ids, 'at' => now()->toDateTimeString()],
            function () use ($notes, $ids, $perPage, $copyMark, $inline) {
                app(LocalCommandRecorder::class)->touch('promissory_notes', $ids);   // basım sayacı toplu güncellenir

                return parent::pdf($notes, $perPage, $copyMark, $inline);
            });
    }
}
