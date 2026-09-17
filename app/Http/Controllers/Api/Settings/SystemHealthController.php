<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ApiController;
use App\Services\System\SystemHealthService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class SystemHealthController extends ApiController
{
    public function __construct(private readonly SystemHealthService $health) {}

    public function index(): JsonResponse
    {
        return response()->json($this->health->check());
    }

    public function failedJobs(Request $request): JsonResponse
    {
        $rows = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get(['id', 'uuid', 'connection', 'queue', 'exception', 'failed_at']);
        $rows = $rows->map(fn ($r) => [
            'id' => $r->id, 'uuid' => $r->uuid, 'queue' => $r->queue,
            'exception' => mb_substr((string) $r->exception, 0, 300), 'failed_at' => $r->failed_at,
        ]);

        return response()->json(['data' => $rows]);
    }

    public function retryFailedJob(string $uuid): JsonResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Audit::log('system.job_retried', "Başarısız kuyruk işini yeniden denedi ({$uuid}).");

        return $this->ok('İş yeniden kuyruğa alındı.');
    }

    public function deleteFailedJob(string $uuid): JsonResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);
        Audit::log('system.job_deleted', "Başarısız kuyruk işini sildi ({$uuid}).");

        return $this->ok('İş kaydı silindi.');
    }
}
