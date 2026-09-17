<?php

namespace App\Http\Controllers\Api\Placement;

use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\ClassWaitlistEntry;
use App\Models\PlacementRun;
use App\Models\Student;
use App\Services\Placement\ClassChangeService;
use App\Services\Placement\ClassStructure;
use App\Services\Placement\PlacementService;
use App\Services\Placement\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sınıflar ve yerleştirme: yapı, otomatik yerleştirme, sınıf değişimi, bekleme listesi, seviye atlatma. */
class PlacementController extends ApiController
{
    public function __construct(
        private readonly PlacementService $placement,
        private readonly ClassChangeService $changes,
        private readonly PromotionService $promotion,
        private readonly ClassStructure $structure,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return response()->json($this->placement->overview($this->placement->term($request->integer('term_id') ?: null)));
    }

    public function preview(Request $request): JsonResponse
    {
        $v = $this->validatePlan($request);

        return response()->json($this->placement->preview($this->placement->term($v['term_id']), $v['levels'], $v['mode'], $v['siblings_apart'] ?? null));
    }

    public function apply(Request $request): JsonResponse
    {
        $v = $this->validatePlan($request, true);
        $run = $this->placement->apply($this->placement->term($v['term_id']), $v['levels'], $v['mode'], $v['siblings_apart'] ?? null, $v['hash'], $v['effective_on'] ?? null);
        $s = $run->summary;

        return $this->ok(sprintf('Yerleştirme uygulandı: %d taşındı, %d yerleşti, %d bekleme listesinde.', $s['moved'], $s['placed'], $s['waitlisted']), ['run' => $this->placement->runRow($run->load('appliedBy:id,name'))]);
    }

    public function revert(PlacementRun $run): JsonResponse
    {
        $this->placement->revert($run);

        return $this->ok('İşlem geri alındı; sınıflar önceki haline döndü.');
    }

    public function pin(Request $request, Student $student): JsonResponse
    {
        $v = $request->validate(['term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')], 'pinned' => ['required', 'boolean']]);
        $this->placement->pin($student, AcademicTerm::query()->findOrFail($v['term_id']), (bool) $v['pinned']);

        return $this->ok($v['pinned'] ? 'Öğrenci şubesine sabitlendi.' : 'Sabitleme kaldırıldı.');
    }

    public function ensureStructure(Request $request): JsonResponse
    {
        $v = $request->validate(['term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')]]);
        $r = $this->placement->ensureStructure(AcademicTerm::query()->findOrFail($v['term_id']));
        $total = count($r['created']) + count($r['adopted']) + count($r['reactivated']);

        return $this->ok($total ? sprintf('Sınıf yapısı hazır: %d sınıf oluşturuldu, %d sınıf eşleştirildi.', count($r['created']), count($r['adopted']) + count($r['reactivated'])) : 'Sınıf yapısı zaten eksiksiz.', ['result' => $r]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['capacity' => ['required', 'integer', 'min:1', 'max:60'], 'siblings_apart' => ['required', 'boolean']], [], ['capacity' => 'Yeni şube kapasitesi', 'siblings_apart' => 'Kardeşleri ayır']);

        return $this->ok('Sınıf ayarları kaydedildi.', ['settings' => $this->placement->saveSettings(['capacity' => (int) $v['capacity'], 'siblings_apart' => (bool) $v['siblings_apart']])]);
    }

    public function studentPanel(Student $student): JsonResponse
    {
        return response()->json($this->changes->studentPanel($student));
    }

    public function changePreview(Request $request, Student $student): JsonResponse
    {
        $v = $request->validate(['class_group_id' => ['required', 'integer']]);

        return response()->json($this->changes->preview($student, ClassGroup::query()->findOrFail($v['class_group_id'])));
    }

    public function change(Request $request, Student $student): JsonResponse
    {
        $v = $request->validate([
            'class_group_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'effective_on' => ['nullable', 'date'],
            'swap_with_student_id' => ['nullable', 'integer', Rule::notIn([$student->id])],
            'to_waitlist' => ['nullable', 'boolean'],
        ], ['reason.required' => 'Sınıf değişimi için gerekçe yazın.', 'reason.min' => 'Gerekçe en az 3 karakter olmalı.'], [
            'class_group_id' => 'Yeni şube', 'reason' => 'Gerekçe', 'effective_on' => 'Geçerlilik tarihi', 'swap_with_student_id' => 'Takas yapılacak öğrenci', 'to_waitlist' => 'Bekleme listesi',
        ]);
        ClassGroup::query()->findOrFail($v['class_group_id']);

        return response()->json($this->changes->change($student, $v));
    }

    public function cancelWaitlist(ClassWaitlistEntry $entry): JsonResponse
    {
        $this->changes->cancelWaitlist($entry);

        return $this->ok('Öğrenci bekleme listesinden çıkarıldı.');
    }

    public function promotionPreview(Request $request): JsonResponse
    {
        [$from, $to] = $this->terms($request);

        return response()->json($this->promotion->preview($from, $to));
    }

    public function promotionApply(Request $request): JsonResponse
    {
        [$from, $to] = $this->terms($request);
        $request->validate(['hash' => ['required', 'string', 'size:40']]);
        $run = $this->promotion->apply($from, $to, (string) $request->input('hash'));

        return $this->ok(sprintf('Seviye atlatma uygulandı: %d öğrenci üst sınıfa geçti, %d öğrenci mezun oldu.', $run->summary['promoted'], $run->summary['graduated']), ['run' => $this->placement->runRow($run->load('appliedBy:id,name'))]);
    }

    private function terms(Request $request): array
    {
        $v = $request->validate([
            'from_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'to_term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
        ]);

        return [AcademicTerm::query()->findOrFail($v['from_term_id']), AcademicTerm::query()->findOrFail($v['to_term_id'])];
    }

    private function validatePlan(Request $request, bool $apply = false): array
    {
        $levels = $this->structure->settings()['levels'];

        return $request->validate([
            'term_id' => ['required', 'integer', Rule::exists('academic_terms', 'id')],
            'levels' => ['required', 'array', 'min:1'],
            'levels.*' => ['integer', Rule::in($levels)],
            'mode' => ['required', Rule::in(['unplaced', 'redistribute'])],
            'siblings_apart' => ['nullable', 'boolean'],
            'hash' => [$apply ? 'required' : 'nullable', 'string', 'size:40'],
            'effective_on' => ['nullable', 'date'],
        ], ['levels.required' => 'En az bir sınıf seviyesi seçin.']);
    }
}
