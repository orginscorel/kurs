<?php

namespace App\Http\Controllers\Api;

use App\Models\ImportJob;
use App\Services\Imports\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel içe aktarma (öğrenci: students.create, öğretmen: teachers.manage).
 * Akış: şablon indir → dosya yükle (önizleme) → onayla → sonuç özeti.
 */
class ImportController extends ApiController
{
    public function __construct(private readonly ImportService $imports) {}

    public function template(Request $request, string $entity): BinaryFileResponse
    {
        $importer = $this->authorizedImporter($request, $entity);
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $this->imports->writeTemplate($importer, $tmp);
        $name = ($entity === 'students' ? 'ogrenci' : 'ogretmen').'-ice-aktarma-sablonu.xlsx';

        return response()->download($tmp, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function preview(Request $request, string $entity): JsonResponse
    {
        $importer = $this->authorizedImporter($request, $entity);
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.required' => 'Bir Excel dosyası seçin.', 'file.max' => 'Dosya en fazla 5 MB olabilir.']);

        ['job' => $job, 'preview' => $preview] = $this->imports->preview($importer, $request->file('file'), $request->user()->id);

        // Kişisel veri (TC vb.) istemciye geri gönderilmez: yalnız başlık, alt metin ve mesajlar
        return response()->json([
            'job' => $this->jobRow($job),
            'summary' => $preview['summary'],
            'unknown_columns' => $preview['unknown_columns'],
            'columns' => array_values(array_map(fn ($c) => $c['label'], array_intersect_key($importer->columns(), array_flip($preview['mapping'])))),
            'rows' => array_map(fn ($r) => array_diff_key($r, ['data' => true]), $preview['rows']),
        ]);
    }

    public function commit(Request $request, ImportJob $job): JsonResponse
    {
        $importer = $this->authorizedImporter($request, $job->entity);
        abort_unless($job->created_by === $request->user()->id || $request->user()->hasRole('super-admin'), 403);

        $result = $this->imports->commit($job, $importer);
        $job->refresh();

        $n = count($result['created']);

        return response()->json([
            'message' => $n ? "{$n} ".mb_strtolower($importer->label())." kaydı oluşturuldu." : 'Hiç kayıt oluşturulmadı.',
            'job' => $this->jobRow($job),
            ...$result,
        ]);
    }

    public function discard(Request $request, ImportJob $job): JsonResponse
    {
        $this->authorizedImporter($request, $job->entity);
        abort_unless($job->created_by === $request->user()->id || $request->user()->hasRole('super-admin'), 403);
        $this->imports->discard($job);

        return $this->ok('Önizleme iptal edildi; yüklenen dosya silindi.');
    }

    public function index(Request $request, string $entity): JsonResponse
    {
        $this->authorizedImporter($request, $entity);
        $jobs = ImportJob::query()->where('entity', $entity)->whereIn('status', ['completed', 'failed'])
            ->with('creator:id,name')->latest('id')->limit(10)->get();

        return response()->json(['data' => $jobs->map(fn (ImportJob $j) => $this->jobRow($j))]);
    }

    private function authorizedImporter(Request $request, string $entity)
    {
        $importer = $this->imports->importer($entity);
        abort_unless($request->user()->can($importer->permission()), 403);

        return $importer;
    }

    private function jobRow(ImportJob $job): array
    {
        $errors = $job->errors ?? [];

        return [
            'id' => $job->id, 'entity' => $job->entity, 'original_name' => $job->original_name, 'status' => $job->status,
            'total_rows' => $job->total_rows, 'success_rows' => $job->success_rows,
            'skipped_count' => count($errors['skipped'] ?? []), 'failed_count' => count($errors['failed'] ?? []),
            'created_by' => $job->relationLoaded('creator') ? $job->creator?->name : null,
            'created_at' => $job->created_at?->toIso8601String(), 'updated_at' => $job->updated_at?->toIso8601String(),
        ];
    }
}
