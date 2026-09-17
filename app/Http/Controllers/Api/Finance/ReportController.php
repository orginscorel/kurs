<?php

namespace App\Http\Controllers\Api\Finance;

use App\Models\AcademicTerm;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceReportService;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends FinanceController
{
    public function __construct(private readonly FinanceReportService $reports) {}

    public function overview(): JsonResponse
    {
        return response()->json($this->reports->overview($this->branchId()));
    }

    public function show(Request $request): JsonResponse
    {
        [$from, $to, $group] = $this->range($request);

        return response()->json([
            'data' => $this->reports->report($this->branchId(), $from, $to, $group),
            'terms' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'ends_on', 'is_current'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'starts_on' => $t->starts_on?->toDateString(), 'ends_on' => $t->ends_on?->toDateString(), 'is_current' => $t->is_current]),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $group] = $this->range($request);
        $r = $this->reports->report($this->branchId(), $from, $to, $group);
        Audit::log('finance_report.exported', sprintf('%s – %s finans raporunu Excel olarak dışa aktardı.', $from->format('d.m.Y'), $to->format('d.m.Y')));

        return $this->xlsx("finans-raporu-{$r['from']}-{$r['to']}.xlsx",
            ['Dönem', 'Öğrenci tahsilatı', 'Diğer gelir', 'Toplam gelir', 'Gider', 'Net', 'Vadesi gelen', 'Vadesi gelenden ödenen', 'Tahsilat oranı (%)', 'Tahsilat adedi'],
            function (callable $add) use ($r) {
                foreach ($r['rows'] as $row) {
                    $add([$row['label'], $this->cell($row['collections']), $this->cell($row['other_income']), $this->cell($row['income']), $this->cell($row['expense']),
                        $this->cell($row['net']), $this->cell($row['due']), $this->cell($row['paid_on_due']), $row['collection_rate'] ?? '', $row['payment_count']]);
                }
                $t = $r['totals'];
                $add(['TOPLAM', $this->cell($t['collections']), $this->cell($t['other_income']), $this->cell($t['income']), $this->cell($t['expense']), $this->cell($t['net']),
                    $this->cell($t['due']), $this->cell($t['paid_on_due']), $t['collection_rate'] ?? '', $t['payment_count']]);
                $add([]);
                $add(['Toplam alacak', $this->cell($r['receivables']['total'])]);
                $add(['Gecikmiş alacak', $this->cell($r['receivables']['overdue'])]);
                $add(['Önümüzdeki 7 gün beklenen', $this->cell($r['receivables']['expected_next_7'])]);
                $add(['Önümüzdeki 30 gün beklenen', $this->cell($r['receivables']['expected_next_30'])]);
                foreach ($r['receivables']['aging'] as $b) {
                    $add(['Yaşlandırma: '.$b['label'], $this->cell($b['amount']), $b['count'].' taksit']);
                }
                $add([]);
                foreach ($r['by_method'] as $m) {
                    $add(['Yöntem: '.$m['label'], $this->cell($m['amount']), $m['count'].' tahsilat']);
                }
                foreach ($r['expense_by_category'] as $c) {
                    $add(['Gider: '.$c['name'], $this->cell($c['amount']), $c['count'].' kayıt']);
                }
            });
    }

    public function pdf(Request $request, FinanceDocuments $documents): Response
    {
        [$from, $to, $group] = $this->range($request);
        $r = $this->reports->report($this->branchId(), $from, $to, $group);
        Audit::log('finance_report.exported', sprintf('%s – %s finans raporunu PDF olarak dışa aktardı.', $from->format('d.m.Y'), $to->format('d.m.Y')));

        $pdf = Pdf::loadView('pdf.finance.report', ['r' => $r, 'institution' => $documents->institution(), 'groupLabel' => FinanceReportService::GROUPS[$r['group']]])
            ->setPaper('a4', 'portrait');
        $name = "finans-raporu-{$r['from']}-{$r['to']}.pdf";

        return $request->boolean('inline') ? $pdf->stream($name) : $pdf->download($name);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(Request $request): array
    {
        $this->validateTr($request, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'group' => ['nullable', 'in:day,week,month'],
        ]);
        $today = CarbonImmutable::today();
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from')) : $today->startOfMonth();
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to')) : $today;

        return [$from, $to, (string) $request->query('group', 'day')];
    }
}
