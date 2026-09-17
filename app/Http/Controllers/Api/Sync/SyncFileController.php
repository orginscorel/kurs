<?php

namespace App\Http\Controllers\Api\Sync;

use App\Http\Controllers\Api\ApiController;
use App\Support\BranchContext;
use App\Sync\Files\FileSyncService;
use App\Sync\Models\SyncDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Dosya eşitleme uçları (yalnız cihaz jetonu). Öğrenci fotoğrafı, belgeler, ödev dosyaları ve teslimleri,
 * disiplin ekleri, logolar. İçerik sha256 ile adreslenir ve doğrulanır. Ayrıntı: docs/SYNC.md › Dosyalar.
 */
class SyncFileController extends ApiController
{
    private function device(Request $request): SyncDevice
    {
        return $request->attributes->get('sync_device');
    }

    public function manifest(Request $request, FileSyncService $files): JsonResponse
    {
        $data = $request->validate(['since' => ['nullable', 'string', 'max:40'], 'limit' => ['nullable', 'integer', 'min:1', 'max:1000']]);
        $device = $this->device($request);

        return response()->json(app(BranchContext::class)->run((int) $device->branch_id,
            fn () => $files->manifest($device, $request->user(), $data['since'] ?? null, (int) ($data['limit'] ?? 500))));
    }

    public function download(Request $request, FileSyncService $files, string $sha256): BinaryFileResponse
    {
        return $files->download($this->device($request), $request->user(), $sha256);
    }

    public function upload(Request $request, FileSyncService $files): JsonResponse
    {
        $maxKb = (int) (FileSyncService::maxBytes() / 1024);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
            'disk' => ['required', 'string', 'in:public,local'],
            'path' => ['required', 'string', 'max:255'],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ], ['file.max' => 'Dosya en çok '.(int) config('sync.file_max_mb', 20).' MB olabilir.']);
        $device = $this->device($request);
        $result = app(BranchContext::class)->run((int) $device->branch_id,
            fn () => $files->upload($device, $request->user(), $request->file('file'), $data['disk'], $data['path'], $data['sha256']));

        return response()->json($result, $result['status'] === 'stored' ? 201 : 200);
    }
}
