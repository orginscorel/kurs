<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ApiController;
use App\Jobs\RunBackupJob;
use App\Models\BackupRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = BackupRun::query()->orderByDesc('started_at');

        return $this->paginated($query->paginate($this->perPage($request, 20)), fn (BackupRun $b) => [
            'id' => $b->id, 'kind' => $b->kind, 'status' => $b->status, 'size' => $b->size, 'checksum' => $b->checksum,
            'error' => $b->error, 'started_at' => $b->started_at?->toIso8601String(), 'finished_at' => $b->finished_at?->toIso8601String(),
            'duration_seconds' => $b->finished_at ? $b->finished_at->diffInSeconds($b->started_at) : null,
        ]);
    }

    /** Manuel yedek: kuyruk işi olarak başlatılır, liste polling ile durumunu izler. */
    public function run(): JsonResponse
    {
        RunBackupJob::dispatch('manual');

        return response()->json(['message' => 'Yedekleme başlatıldı. Birkaç dakika sürebilir, listeyi yenileyerek izleyebilirsiniz.'], 202);
    }

    public function download(BackupRun $backupRun): StreamedResponse
    {
        abort_unless($backupRun->status === 'success' && $backupRun->path, 404);

        return Storage::disk('local')->download($backupRun->path);
    }
}
