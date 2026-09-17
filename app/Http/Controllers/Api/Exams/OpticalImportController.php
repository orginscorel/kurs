<?php

namespace App\Http\Controllers\Api\Exams;

use App\Http\Controllers\Api\ApiController;
use App\Models\Exam;
use App\Models\OpticalImport;
use App\Models\OpticalLayout;
use App\Services\Exams\Optical\OpticalImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class OpticalImportController extends ApiController
{
    use Concerns\TurkishValidation;

    public function __construct(private readonly OpticalImportService $optical) {}

    /** Son içe aktarmalar (tüm sınavlar ya da tek sınav). */
    public function index(Request $request): JsonResponse
    {
        $query = OpticalImport::query()->with(['exam:id,name,exam_date,status', 'creator:id,name'])
            ->whereHas('exam')
            ->when($request->integer('exam_id'), fn ($q, $id) => $q->where('exam_id', $id))
            ->orderByDesc('id');

        return $this->paginated($query->paginate($this->perPage($request, 20)), fn (OpticalImport $i) => $this->optical->serialize($i) + [
            'exam' => $i->exam ? ['id' => $i->exam->id, 'name' => $i->exam->name, 'exam_date' => $i->exam->exam_date->toDateString(), 'status' => $i->exam->status] : null,
        ]);
    }

    public function show(Exam $exam, OpticalImport $import): JsonResponse
    {
        abort_unless($import->exam_id === $exam->id, 404);
        $import->load('creator:id,name');

        return response()->json(['import' => $this->optical->serialize($import)]);
    }

    /** Sihirbaz adım 1: dosya yükle → kolonlar + örnek satırlar + önerilen eşleşme. */
    public function upload(Request $request, Exam $exam): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx,xls,json,dat,prn'],
            'format' => ['nullable', Rule::in(['csv', 'xlsx', 'txt', 'json'])],
        ], $this->messages(['file.mimes' => 'Yalnızca CSV, TXT, XLSX ve JSON dosyaları yüklenebilir.', 'file.max' => 'Dosya 20 MB’tan büyük olamaz.']), $this->attributes());

        return response()->json($this->optical->upload($exam, $request->file('file'), $request->input('format')), 201);
    }

    /** Yüklenmiş dosyanın sihirbaz bilgisini yeniden verir (sayfa yenilenirse). */
    public function describe(Exam $exam, OpticalImport $import): JsonResponse
    {
        abort_unless($import->exam_id === $exam->id, 404);

        return response()->json($this->optical->describe($exam, $import));
    }

    /** Sihirbaz adım 3: eşleşme önizlemesi (eşleşen/eşleşmeyen öğrenci, kitapçık algılama). */
    public function preview(Request $request, Exam $exam, OpticalImport $import): JsonResponse
    {
        abort_unless($import->exam_id === $exam->id, 404);
        $data = $request->validate(['mapping' => ['required', 'array']]);

        return response()->json($this->optical->preview($exam, $import, $data['mapping']));
    }

    /** Sihirbaz adım 4: içe aktar (küçük dosya anında, büyük dosya kuyrukta). */
    public function run(Request $request, Exam $exam, OpticalImport $import): JsonResponse
    {
        abort_unless($import->exam_id === $exam->id, 404);
        $data = $request->validate(['mapping' => ['required', 'array'], 'save_layout_as' => ['nullable', 'string', 'max:80']]);
        $import = $this->optical->run($exam, $import, $data['mapping'], $data['save_layout_as'] ?? null);

        $msg = match ($import->status) {
            'completed' => "İçe aktarma tamamlandı: {$import->matched_rows}/{$import->total_rows} öğrenci eşleşti.",
            'queued' => 'Dosya kuyruğa alındı; ilerleme bu ekranda güncellenir.',
            'failed' => 'İçe aktarma başarısız: '.$import->error,
            default => 'İçe aktarma başlatıldı.',
        };

        return response()->json(['message' => $msg, 'import' => $this->optical->serialize($import)]);
    }

    /**
     * API ile satır gönderimi (jeton): POST /exams/{exam}/optical
     * {"rows":[{"student_no":"2026001","booklet":"A","answers":{"TUR":"ABCD…","MAT":"…"}}]}
     * ya da "answers":"tüm cevaplar tek dizi" (bölümler sınav sırasıyla kesilir).
     */
    public function api(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.student_no' => ['required', 'string', 'max:30'],
            'rows.*.name' => ['nullable', 'string', 'max:160'],
            'rows.*.booklet' => ['nullable', 'string', 'max:4'],
            'rows.*.answers' => ['required'],
            'rows.*.national_rank' => ['nullable', 'integer', 'min:1'],
        ], $this->messages(), $this->attributes());
        $import = $this->optical->importApi($exam, $data['rows']);

        return response()->json([
            'message' => "{$import->matched_rows}/{$import->total_rows} öğrenci sonucu kaydedildi.",
            'import' => $this->optical->serialize($import),
        ], 201);
    }

    // ------------------------------------------------------------------ düzen şablonları

    public function layouts(Request $request): JsonResponse
    {
        return response()->json(['data' => OpticalLayout::query()->when($request->query('format'), fn ($q, $f) => $q->where('format', $f))->orderBy('name')->get(['id', 'name', 'format', 'mapping', 'created_at'])]);
    }

    public function storeLayout(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'format' => ['required', Rule::in(['csv', 'xlsx', 'txt', 'json'])], 'mapping' => ['required', 'array']]);
        $layout = OpticalLayout::query()->updateOrCreate(['branch_id' => app(\App\Support\BranchContext::class)->id(), 'name' => $data['name'], 'format' => $data['format']], ['mapping' => $data['mapping'], 'created_by' => Auth::id()]);

        return response()->json(['message' => 'Düzen şablonu kaydedildi.', 'data' => $layout], 201);
    }

    public function destroyLayout(OpticalLayout $layout): JsonResponse
    {
        $layout->delete();

        return $this->ok('Düzen şablonu silindi.');
    }
}
