<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\FinanceAccount;
use App\Models\Guardian;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Student;
use App\Services\Accounting\Dec;
use App\Services\Accounting\FinanceAnalytics;
use App\Services\Accounting\FinanceSettings;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceExtraDocuments;
use App\Services\Finance\PaymentService;
use App\Services\Finance\StatementService;
use App\Services\Finance\StudentCredit;
use App\Services\Invoicing\Integrators\IntegratorManager;
use App\Support\Money;
use App\Support\Sensitive;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finans kokpiti ek göstergeleri, cari ekstre, veli toplu tahsilatı, finans ayarları ve Rapor Merkezi analizleri.
 */
class FinanceHubController extends FinanceController
{
    // ================================================================== kokpit

    public function cockpit(): JsonResponse
    {
        $b = $this->branchId();
        $today = CarbonImmutable::today();
        $t = $today->toDateString();

        $unbilled = app(\App\Services\Invoicing\InvoiceService::class)->unbilledQuery($b)
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(p.amount - COALESCE(l.linked, 0) - COALESCE(r.refunded, 0)), 0) AS s')->first();
        $pos = DB::table('payment_card_details as d')->join('payments as p', 'p.id', '=', 'd.payment_id')->where('d.branch_id', $b)->whereNull('d.pos_settlement_id')->whereNull('p.voided_at')
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(d.net_amount), 0) AS n, SUM(d.expected_deposit_date < ?) AS late, COALESCE(SUM(CASE WHEN d.expected_deposit_date < ? THEN d.net_amount ELSE 0 END), 0) AS late_amount', [$t, $t])->first();
        $bankOpen = DB::table('account_transactions as x')->join('finance_accounts as a', 'a.id', '=', 'x.finance_account_id')
            ->leftJoin('reconciliation_marks as m', 'm.account_transaction_id', '=', 'x.id')
            ->where('x.branch_id', $b)->where('a.kind', 'bank')->whereNull('m.id')->where('x.occurred_at', '>=', $today->subDays(90))->count();
        $drafts = DB::table('invoices')->where('branch_id', $b)->where('status', 'draft')->selectRaw('COUNT(*) AS c, COALESCE(SUM(payable_total), 0) AS s')->first();
        $overdueInvoices = $this->overdueInvoiceQuery($b, $t)->selectRaw('COUNT(*) AS c, COALESCE(SUM(inv.payable_total - COALESCE(lk.linked, 0)), 0) AS s')->first();
        $promises = DB::table('collection_notes')->where('branch_id', $b)->where('kind', 'promise')->where('status', 'open')
            ->selectRaw('SUM(promised_date = ?) AS today, SUM(promised_date < ?) AS late, COALESCE(SUM(CASE WHEN promised_date = ? THEN promised_amount ELSE 0 END), 0) AS today_amount', [$t, $t, $t])->first();
        $refunds = DB::table('refunds')->where('branch_id', $b)->whereNull('voided_at')->where('refunded_at', '>=', $today->startOfMonth())
            ->selectRaw('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS s')->first();
        $period = DB::table('accounting_periods')->where('branch_id', $b)->where('status', 'closed')->max('period');
        $lastMonth = $today->subMonthNoOverflow()->format('Y-m');

        return response()->json(['data' => [
            'unbilled' => ['count' => (int) $unbilled->c, 'amount' => Dec::round(Dec::norm($unbilled->s))],
            'pos_pending' => ['count' => (int) $pos->c, 'net' => Dec::round(Dec::norm($pos->n)), 'late' => (int) $pos->late, 'late_amount' => Dec::round(Dec::norm($pos->late_amount))],
            'bank_unmatched' => $bankOpen,
            'draft_invoices' => ['count' => (int) $drafts->c, 'amount' => Dec::round(Dec::norm($drafts->s))],
            'overdue_invoices' => ['count' => (int) $overdueInvoices->c, 'amount' => Dec::round(Dec::norm($overdueInvoices->s))],
            'promises' => ['today' => (int) ($promises->today ?? 0), 'late' => (int) ($promises->late ?? 0), 'today_amount' => Dec::round(Dec::norm($promises->today_amount ?? 0))],
            'refunds_month' => ['count' => (int) $refunds->c, 'amount' => Dec::round(Dec::norm($refunds->s))],
            'last_closed_period' => $period,
            'suggest_close' => $period !== $lastMonth && ! DB::table('accounting_periods')->where('branch_id', $b)->where('period', $lastMonth)->exists(),
            'integrator' => app(IntegratorManager::class)->current()->label(),
        ]]);
    }

    /**
     * Vadesi geçmiş kesilmiş faturalar: tahsilatla kapanmamış (bağlı tahsilat < ödenecek) satış faturaları.
     * Fatura vadesi tutulmadığı için vade = düzenleme tarihi + `accounting.invoice_due_days` (varsayılan 0 gün).
     */
    public static function overdueInvoiceQuery(int $branchId, string $today)
    {
        $days = (int) (Settings::get('accounting.invoice_due_days', 0, $branchId) ?? 0);
        $limit = CarbonImmutable::parse($today)->subDays($days)->toDateString();

        return DB::table('invoices as inv')
            ->leftJoinSub(DB::table('invoice_payments')->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS linked'), 'lk', 'lk.invoice_id', '=', 'inv.id')
            ->where('inv.branch_id', $branchId)->where('inv.status', 'issued')->where('inv.kind', 'sales')
            ->where('inv.issue_date', '<', $limit)
            ->whereRaw('inv.payable_total - COALESCE(lk.linked, 0) > 0');
    }

    /** Yönetim panosu uyarı bandı: vadesi geçmiş taksit + fatura, bugün vadesi dolanlar. Düz dizi döner. */
    public static function overdueAlert(int $branchId): array
    {
        $t = CarbonImmutable::today()->toDateString();
        $inst = DB::table('installments as i')->join('students as s', 's.id', '=', 'i.student_id')->whereNull('s.deleted_at')
            ->where('i.branch_id', $branchId)->whereIn('i.status', ['pending', 'partial', 'overdue'])
            ->selectRaw('SUM(i.due_date < ?) AS oc, COALESCE(SUM(CASE WHEN i.due_date < ? THEN i.amount - i.paid_amount ELSE 0 END), 0) AS oa,
                COUNT(DISTINCT CASE WHEN i.due_date < ? THEN i.student_id END) AS os,
                SUM(i.due_date = ?) AS tc, COALESCE(SUM(CASE WHEN i.due_date = ? THEN i.amount - i.paid_amount ELSE 0 END), 0) AS ta', [$t, $t, $t, $t, $t])->first();
        $inv = self::overdueInvoiceQuery($branchId, $t)->selectRaw('COUNT(*) AS c, COALESCE(SUM(inv.payable_total - COALESCE(lk.linked, 0)), 0) AS s')->first();

        return [
            'date' => $t,
            'installments' => ['count' => (int) ($inst->oc ?? 0), 'amount' => Dec::round(Dec::norm($inst->oa ?? 0)), 'students' => (int) ($inst->os ?? 0)],
            'invoices' => ['count' => (int) ($inv->c ?? 0), 'amount' => Dec::round(Dec::norm($inv->s ?? 0))],
            'due_today' => ['count' => (int) ($inst->tc ?? 0), 'amount' => Dec::round(Dec::norm($inst->ta ?? 0))],
        ];
    }

    public function alert(): JsonResponse
    {
        return response()->json(['data' => self::overdueAlert($this->branchId())]);
    }

    // ================================================================== cari ekstre

    public function studentStatement(Request $request, Student $student, StatementService $statements): JsonResponse
    {
        [$from, $to] = $this->optionalRange($request);

        return response()->json(['data' => $statements->build([$student->id], $from, $to) + ['holder' => $statements->holderForStudent($student)]]);
    }

    public function studentStatementPdf(Request $request, Student $student, StatementService $statements, FinanceExtraDocuments $docs): Response
    {
        [$from, $to] = $this->optionalRange($request);
        FinanceAudit::log('statement.exported', "{$student->full_name} öğrencisinin cari hesap ekstresini PDF olarak aldı.", $student);

        return $docs->statement($statements->build([$student->id], $from, $to), $statements->holderForStudent($student), $from, $to, Str::slug($student->full_name), $request->boolean('inline'));
    }

    public function guardianStatement(Request $request, Guardian $guardian, StatementService $statements): JsonResponse
    {
        [$from, $to] = $this->optionalRange($request);
        $ids = $statements->guardianStudentIds($guardian);

        return response()->json(['data' => $statements->build($ids, $from, $to) + ['holder' => $this->guardianHolder($guardian, count($ids))]]);
    }

    public function guardianStatementPdf(Request $request, Guardian $guardian, StatementService $statements, FinanceExtraDocuments $docs): Response
    {
        [$from, $to] = $this->optionalRange($request);
        $ids = $statements->guardianStudentIds($guardian);
        FinanceAudit::log('statement.exported', "{$guardian->full_name} velisinin cari hesap ekstresini PDF olarak aldı.", $guardian);

        return $docs->statement($statements->build($ids, $from, $to), $this->guardianHolder($guardian, count($ids)), $from, $to, Str::slug($guardian->first_name.' '.$guardian->last_name), $request->boolean('inline'));
    }

    private function guardianHolder(Guardian $g, int $children): array
    {
        return ['name' => trim($g->first_name.' '.$g->last_name), 'sub' => "Veli · {$children} öğrenci", 'address' => $g->address];
    }

    // ================================================================== veli toplu tahsilat

    public function guardianSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['data' => []]);
        }
        $sensitive = $request->user()->can('students.view_sensitive');
        $digits = preg_replace('/\D/', '', $q);
        $rows = Guardian::query()->where(fn ($w) => $w->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$q}%"])
            ->when(strlen($digits) >= 4, fn ($x) => $x->orWhere('phone', 'like', "%{$digits}%"))
            ->orWhereIn('id', DB::table('guardian_student as gs')->join('students as s', 's.id', '=', 'gs.student_id')->where('s.full_name', 'like', "%{$q}%")->select('gs.guardian_id')))
            ->limit(10)->get();
        $children = DB::table('guardian_student as gs')->join('students as s', 's.id', '=', 'gs.student_id')->whereIn('gs.guardian_id', $rows->pluck('id'))->whereNull('s.deleted_at')
            ->get(['gs.guardian_id', 's.full_name'])->groupBy('guardian_id');

        return response()->json(['data' => $rows->map(fn (Guardian $g) => [
            'id' => $g->id, 'name' => trim($g->first_name.' '.$g->last_name), 'phone' => $sensitive ? $g->phone : Sensitive::maskPhone($g->phone),
            'children' => ($children[$g->id] ?? collect())->pluck('full_name')->values(),
        ])]);
    }

    public function guardianContext(Request $request, Guardian $guardian, StatementService $statements): JsonResponse
    {
        $ids = $statements->guardianStudentIds($guardian);
        $today = CarbonImmutable::today();
        $students = Student::query()->whereIn('id', $ids)->orderBy('full_name')->get(['id', 'full_name', 'student_no', 'status', 'photo_path']);
        $open = Installment::query()->whereIn('student_id', $ids)->whereIn('status', ['pending', 'partial', 'overdue'])
            ->with('enrollment:id,enrollment_no,program_id', 'enrollment.program:id,name')->orderBy('due_date')->orderBy('sequence')->get()->groupBy('student_id');

        return response()->json(['data' => [
            'guardian' => ['id' => $guardian->id, 'name' => trim($guardian->first_name.' '.$guardian->last_name)],
            'students' => $students->map(function (Student $s) use ($open, $today) {
                $rows = $open[$s->id] ?? collect();

                return [
                    'id' => $s->id, 'full_name' => $s->full_name, 'student_no' => $s->student_no,
                    'remaining' => $rows->reduce(fn ($x, Installment $i) => bcadd($x, $i->remaining(), 2), '0.00'),
                    'overdue' => $rows->filter(fn (Installment $i) => $i->due_date->lt($today))->reduce(fn ($x, Installment $i) => bcadd($x, $i->remaining(), 2), '0.00'),
                    'credit' => StudentCredit::forStudent($s->id),
                    'installments' => $rows->map(fn (Installment $i) => [
                        'id' => $i->id, 'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(), 'amount' => (string) $i->amount,
                        'remaining' => $i->remaining(), 'program' => $i->enrollment?->program?->name, 'days_overdue' => max(0, (int) $i->due_date->diffInDays($today, false)),
                    ])->values(),
                ];
            }),
        ]]);
    }

    /**
     * Bir velinin birden çok çocuğu için tek işlemde tahsilat: çocuk başına ayrı makbuz, hepsi ya da hiçbiri.
     * İdempotentlik: istemci anahtarı + öğrenci id.
     */
    public function bulkCollect(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $this->validateTr($request, [
            'guardian_id' => ['required', 'integer'],
            'finance_account_id' => ['required', 'integer'],
            'method' => ['required', Rule::in(array_keys(Payment::METHODS))],
            'paid_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(10)->toDateTimeString()],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.student_id' => ['required', 'integer', 'distinct'],
            'items.*.amount' => ['required', 'string', self::MONEY],
            'items.*.installment_ids' => ['nullable', 'array', 'max:100'],
            'items.*.installment_ids.*' => ['integer'],
            'idempotency_key' => ['required', 'string', 'max:60'],
        ], ['items.required' => 'En az bir öğrenci için tutar girin.']);
        $guardian = Guardian::query()->findOrFail($data['guardian_id']);
        $account = FinanceAccount::query()->findOrFail($data['finance_account_id']);
        if (! $account->is_active) {
            throw new BusinessRuleException("\"{$account->name}\" hesabı pasif durumda.", 'account_inactive');
        }
        $allowed = app(StatementService::class)->guardianStudentIds($guardian);
        $name = trim($guardian->first_name.' '.$guardian->last_name);

        $created = DB::transaction(function () use ($data, $allowed, $payments, $account, $guardian, $name) {
            $out = [];
            foreach ($data['items'] as $item) {
                if (! in_array((int) $item['student_id'], $allowed, true)) {
                    throw new BusinessRuleException('Seçilen öğrencilerden biri bu veliye bağlı değil.', 'guardian_mismatch');
                }
                if (! Money::isPositive(Money::of($item['amount']))) {
                    continue;
                }
                $student = Student::query()->findOrFail($item['student_id']);
                if (! empty($item['installment_ids'])) {
                    $found = Installment::query()->where('student_id', $student->id)->whereIn('id', $item['installment_ids'])->count();
                    if ($found !== count(array_unique($item['installment_ids']))) {
                        throw new BusinessRuleException('Seçilen taksitlerden bazıları öğrenciye ait değil.', 'installment_mismatch');
                    }
                }
                $out[] = $payments->collect($student, [
                    'finance_account_id' => $account->id, 'method' => $data['method'], 'amount' => $item['amount'],
                    'paid_at' => $data['paid_at'] ?? null, 'installment_ids' => $item['installment_ids'] ?? null,
                    'guardian_id' => $guardian->id, 'payer_name' => $name, 'reference' => $data['reference'] ?? null,
                    'note' => trim(($data['note'] ?? '').' (veli toplu tahsilatı)'),
                    'idempotency_key' => mb_substr($data['idempotency_key'].':'.$student->id, 0, 80),
                    'overpayment' => 'next',
                ]);
            }
            if (! $out) {
                throw new BusinessRuleException('Tahsil edilecek tutar girilmedi.', 'invalid_amount');
            }

            return $out;
        });

        $total = array_reduce($created, fn ($s, Payment $p) => bcadd($s, (string) $p->amount, 2), '0.00');
        FinanceAudit::log('payment.bulk_guardian', sprintf('%s velisinden %d öğrenci için toplam %s TL tahsilat aldı.', $name, count($created), Money::format($total)), $guardian);

        return response()->json([
            'message' => sprintf('%d makbuz oluşturuldu, toplam %s TL.', count($created), Money::format($total)),
            'total' => $total,
            'data' => collect($created)->map(fn (Payment $p) => ['id' => $p->id, 'receipt_no' => $p->receipt_no, 'amount' => (string) $p->amount, 'student_id' => $p->student_id,
                'student' => DB::table('students')->where('id', $p->student_id)->value('full_name')])->values(),
        ], 201);
    }

    public function applyCredit(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $data = $this->validateTr($request, ['installment_ids' => ['nullable', 'array', 'max:100'], 'installment_ids.*' => ['integer']]);
        $applied = $payments->applyCredit($payment, $data['installment_ids'] ?? null);

        return $this->ok(sprintf('%s TL avans açık taksitlere mahsup edildi.', Money::format($applied)), ['applied' => $applied]);
    }

    // ================================================================== ayarlar

    public function settings(IntegratorManager $integrators): JsonResponse
    {
        return response()->json(['data' => FinanceSettings::all() + [
            'integrators' => $integrators->options(),
            'invoice_due_days' => (int) (Settings::get('accounting.invoice_due_days', 0) ?? 0),
            'portal_show_student_overdue' => (bool) Settings::get('portal.show_student_overdue_alert', true),
            'portal_show_guardian_overdue' => (bool) Settings::get('portal.show_guardian_overdue_alert', true),
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'auto_journal' => ['boolean'],
            'invoice_prefix' => ['required', 'regex:/^[A-Za-z0-9]{3}$/'],
            'return_prefix' => ['required', 'regex:/^[A-Za-z0-9]{3}$/', 'different:invoice_prefix'],
            'default_document_type' => ['required', Rule::in(array_keys(\App\Models\Invoice::DOCUMENT_TYPES))],
            'vat_rates' => ['required', 'array', 'min:1', 'max:8'],
            'vat_rates.*' => ['regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'default_vat_rate' => ['required', 'regex:/^\d{1,2}([.,]\d{1,2})?$/'],
            'prices_include_vat' => ['boolean'],
            'withholding_enabled' => ['boolean'],
            'default_unit' => ['required', 'string', 'max:12'],
            'service_description' => ['required', 'string', 'min:3', 'max:120'],
            'invoice_note' => ['nullable', 'string', 'max:500'],
            'integrator' => ['required', Rule::in(array_keys(IntegratorManager::DRIVERS))],
            'pos_commission_rate' => ['required', 'regex:/^\d{1,2}([.,]\d{1,3})?$/'],
            'pos_settlement_days' => ['required', 'integer', 'min:0', 'max:60'],
            'invoice_due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'portal_show_student_overdue' => ['boolean'],
            'note_payee' => ['nullable', 'string', 'max:200'],
            'note_place' => ['nullable', 'string', 'max:120'],
            'note_court' => ['nullable', 'string', 'max:60'],
            'note_consideration' => ['nullable', 'string', 'max:80'],
            'note_acceleration' => ['boolean'],
            'portal_show_guardian_overdue' => ['boolean'],
        ], ['invoice_prefix.regex' => 'Fatura öneki 3 harf/rakam olmalı (GİB biçimi).', 'return_prefix.different' => 'İade faturası öneki satış önekinden farklı olmalı.']);

        $rates = array_values(array_unique(array_map(fn ($r) => str_replace(',', '.', $r), $data['vat_rates'])));
        $default = str_replace(',', '.', $data['default_vat_rate']);
        if (! in_array(rtrim(rtrim(Dec::round($default), '0'), '.'), array_map(fn ($r) => rtrim(rtrim(Dec::round($r), '0'), '.'), $rates), true)) {
            throw new BusinessRuleException('Varsayılan KDV oranı oran listesinde olmalı.', 'default_rate_missing');
        }
        $before = FinanceSettings::all();
        $values = [
            'auto_journal' => (bool) ($data['auto_journal'] ?? true),
            'invoice_prefix' => strtoupper($data['invoice_prefix']), 'return_prefix' => strtoupper($data['return_prefix']),
            'default_document_type' => $data['default_document_type'], 'vat_rates' => $rates, 'default_vat_rate' => $default,
            'prices_include_vat' => (bool) ($data['prices_include_vat'] ?? true), 'withholding_enabled' => (bool) ($data['withholding_enabled'] ?? false),
            'default_unit' => mb_strtoupper(trim($data['default_unit'])), 'service_description' => trim($data['service_description']),
            'invoice_note' => $data['invoice_note'] ?? null, 'integrator' => $data['integrator'],
            'pos_commission_rate' => str_replace(',', '.', $data['pos_commission_rate']), 'pos_settlement_days' => (int) $data['pos_settlement_days'],
            'invoice_due_days' => (int) ($data['invoice_due_days'] ?? 0),
            'note_payee' => $data['note_payee'] ?? null,
            'note_place' => $data['note_place'] ?? null,
            'note_court' => $data['note_court'] ?? 'Erbaa',
            'note_consideration' => $data['note_consideration'] ?? 'eğitim hizmeti karşılığı',
            'note_acceleration' => (bool) ($data['note_acceleration'] ?? true),
        ];
        Settings::put('accounting', $values);
        Settings::put('portal', [
            'show_student_overdue_alert' => (bool) ($data['portal_show_student_overdue'] ?? true),
            'show_guardian_overdue_alert' => (bool) ($data['portal_show_guardian_overdue'] ?? true),
        ]);
        FinanceAudit::log('settings.finance_updated', 'Finans / fatura / muhasebe ayarlarını güncelledi.', null, ['before' => $before, 'after' => FinanceSettings::all()]);

        return $this->ok('Finans ayarları kaydedildi.');
    }

    // ================================================================== analizler (Rapor Merkezi)

    public function analytics(Request $request, string $key, FinanceAnalytics $analytics): JsonResponse
    {
        [$from, $to] = $this->reportRange($request);

        return response()->json(['data' => $analytics->run($key, $this->branchId(), $from, $to), 'reports' => FinanceAnalytics::REPORTS]);
    }

    public function analyticsExport(Request $request, string $key, FinanceAnalytics $analytics): StreamedResponse
    {
        [$from, $to] = $this->reportRange($request);
        $r = $analytics->run($key, $this->branchId(), $from, $to);
        FinanceAudit::log('finance_report.exported', sprintf('"%s" raporunu (%s – %s) Excel olarak dışa aktardı.', $r['title'], $from->format('d.m.Y'), $to->format('d.m.Y')));

        return $this->xlsx("{$key}-{$r['from']}-{$r['to']}.xlsx", [$r['title'], CarbonImmutable::parse($r['from'])->format('d.m.Y').' – '.CarbonImmutable::parse($r['to'])->format('d.m.Y')],
            function (callable $add) use ($r) {
                foreach ($r['kpis'] ?? [] as $k) {
                    $add([$k['label'], $this->excelValue($k['value'], $k['type'])]);
                }
                foreach ($r['tables'] as $t) {
                    $add([]);
                    $add([$t['title']]);
                    $add(array_column($t['columns'], 'label'));
                    foreach ($t['rows'] as $row) {
                        $add(array_map(fn ($c) => $this->excelValue($row[$c['key']] ?? null, $c['type']), $t['columns']));
                    }
                    if (! empty($t['totals'])) {
                        $add(array_map(fn ($c) => $this->excelValue($t['totals'][$c['key']] ?? null, $c['type']), $t['columns']));
                    }
                }
                $add([]);
                foreach ($r['notes'] ?? [] as $n) {
                    $add(['Not: '.$n]);
                }
            });
    }

    public function analyticsPdf(Request $request, string $key, FinanceAnalytics $analytics, FinanceExtraDocuments $docs): Response
    {
        [$from, $to] = $this->reportRange($request);
        $r = $analytics->run($key, $this->branchId(), $from, $to);
        FinanceAudit::log('finance_report.exported', sprintf('"%s" raporunu (%s – %s) PDF olarak dışa aktardı.', $r['title'], $from->format('d.m.Y'), $to->format('d.m.Y')));

        return $docs->analytics($r, $request->boolean('inline'));
    }

    private function excelValue(mixed $v, string $type): mixed
    {
        if ($v === null) {
            return '';
        }

        return match ($type) {
            'money' => $this->cell($v),
            'percent', 'number' => is_numeric($v) ? (float) $v : $v,
            default => (string) $v,
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function reportRange(Request $request): array
    {
        $this->validateTr($request, ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $today = CarbonImmutable::today();

        return [
            $request->query('from') ? CarbonImmutable::parse($request->query('from')) : $today->startOfYear(),
            $request->query('to') ? CarbonImmutable::parse($request->query('to')) : $today,
        ];
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function optionalRange(Request $request): array
    {
        $this->validateTr($request, ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return [
            $request->query('from') ? CarbonImmutable::parse($request->query('from')) : null,
            $request->query('to') ? CarbonImmutable::parse($request->query('to')) : null,
        ];
    }
}
