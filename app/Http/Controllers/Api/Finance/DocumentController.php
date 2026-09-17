<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PromissoryNote;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceExtraDocuments;
use App\Services\Finance\PromissoryNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Toplu makbuz / fatura PDF, işlem dekontu ve senet (bono) basımı. */
class DocumentController extends FinanceController
{
    public function __construct(private readonly FinanceExtraDocuments $docs) {}

    public function receipts(Request $request): Response
    {
        $ids = $this->ids($request);
        $payments = Payment::query()->whereIn('id', $ids)->orderBy('paid_at')->get();
        if ($payments->count() > 1) {
            FinanceAudit::log('payment.bulk_printed', "{$payments->count()} tahsilat makbuzunu toplu PDF olarak aldı.");
        }

        return $this->docs->receipts($payments, $request->boolean('inline'));
    }

    public function invoices(Request $request): Response
    {
        $invoices = Invoice::query()->whereIn('id', $this->ids($request))->orderBy('issue_date')->orderBy('id')->get();
        if ($invoices->count() > 1) {
            FinanceAudit::log('invoice.bulk_printed', "{$invoices->count()} faturayı toplu PDF olarak aldı.");
        }

        return $this->docs->invoices($invoices, $request->boolean('inline'));
    }

    public function voucher(Request $request, string $type, int $id): Response
    {
        abort_unless(in_array($type, FinanceExtraDocuments::VOUCHER_TYPES, true), 404);

        return $this->docs->voucher($type, $id, $request->boolean('inline'));
    }

    // ------------------------------------------------------------------ senet

    /** Basım önizlemesi: seçilen/filtrelenen taksitler, borçlu bilgisi, eksik alanlar. */
    public function notesPreview(Request $request, PromissoryNoteService $notes): JsonResponse
    {
        $filters = $this->noteFilters($request);
        $installments = $notes->query($filters)->limit(PromissoryNoteService::MAX + 1)->get();
        $preview = $notes->preview($installments->take(PromissoryNoteService::MAX), $request->user()->can('students.view_sensitive'));

        return response()->json(['data' => $preview + ['truncated' => $installments->count() > PromissoryNoteService::MAX, 'max' => PromissoryNoteService::MAX]]);
    }

    public function notesPrepare(Request $request, PromissoryNoteService $notes): JsonResponse
    {
        $filters = $this->noteFilters($request);
        if (empty($filters['installment_ids']) && empty($filters['enrollment_ids']) && empty($filters['term_id']) && empty($filters['program_id'])
            && empty($filters['class_group_id']) && empty($filters['due_from']) && empty($filters['due_to']) && empty($filters['student_id'])) {
            return response()->json(['message' => 'Basım için taksit seçin ya da en az bir filtre girin.'], 422);
        }
        $prepared = $notes->prepare($notes->query($filters)->limit(PromissoryNoteService::MAX + 1)->get());

        return response()->json(['data' => ['ids' => $prepared->pluck('id')->values(), 'count' => $prepared->count()]]);
    }

    public function notesPdf(Request $request, PromissoryNoteService $notes): Response
    {
        $this->validateTr($request, ['per_page' => ['nullable', 'in:1,2,3'], 'copy' => ['nullable', 'boolean']]);
        $ids = $this->ids($request, PromissoryNoteService::MAX);
        $list = PromissoryNote::query()->whereIn('id', $ids)->get()
            ->sortBy(fn ($n) => array_search($n->id, $ids, true))->values();

        return $notes->pdf($list, (int) $request->query('per_page', 3), $request->boolean('copy', true), $request->boolean('inline'));
    }

    private function noteFilters(Request $request): array
    {
        $data = $this->validateTr($request, [
            'installment_ids' => ['nullable', 'array', 'max:'.PromissoryNoteService::MAX], 'installment_ids.*' => ['integer'],
            'enrollment_ids' => ['nullable', 'array', 'max:200'], 'enrollment_ids.*' => ['integer'],
            'term_id' => ['nullable', 'integer'], 'program_id' => ['nullable', 'integer'], 'class_group_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'],
            'due_from' => ['nullable', 'date_format:Y-m-d'], 'due_to' => ['nullable', 'date_format:Y-m-d'],
            'include_paid' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $data['include_paid'] = $request->boolean('include_paid');

        return $data;
    }

    /** @return list<int> */
    private function ids(Request $request, int $max = FinanceExtraDocuments::BULK_MAX): array
    {
        $raw = $request->input('ids', $request->query('ids'));
        $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        abort_if($ids === [] || count($ids) > $max, 422, 'Geçersiz belge seçimi.');

        return $ids;
    }
}
