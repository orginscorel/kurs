<?php

namespace App\Sync\Commands;

use App\Models\AccountTransfer;
use App\Models\CollectionNote;
use App\Models\Enrollment;
use App\Models\FinanceEntry;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PosSettlement;
use App\Models\PromissoryNote;
use App\Models\Refund;
use App\Models\Student;
use App\Services\Finance\AccountService;
use App\Services\Finance\CollectionService;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\FinanceAudit;
use App\Services\Finance\FinanceEntryService;
use App\Services\Finance\InstallmentPlanService;
use App\Services\Finance\PaymentService;
use App\Services\Finance\PromissoryNoteService;
use App\Services\Finance\ReconciliationService;
use App\Services\Finance\RefundService;
use App\Support\Money;
use App\Sync\SyncContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sunucu tarafı finans komut işleyicileri: HER ZAMAN asıl servis (iş kuralları, yevmiye, olaylar).
 * Para hareketi asla kaybolmaz:
 *  - tahsilat: aynı taksit çevrimdışıyken başka yerden ödenmişse fazlası avans + mutabakat
 *  - iade: tahsilat sunucuda iptal/iade edilmişse iade edilebilen kısım iade, artan kısım "mükerrer iade farkı"
 *    gideri olarak kasadan düşülür + mutabakat
 *  - kasa sunucu bakiyesine göre eksiye düşerse işlem yine yazılır + mutabakat (Ledger / SyncContext)
 * Kimlik: aynı uuid ve belge numaralarıyla (SyncContext::withPresets); tekrar gelen komut sync_receipts ile tekil.
 *
 * $meta: base_time (cihazın son gördüğü sunucu anı), reconcile(Model, not), unused(tablo, uuid[]).
 */
class FinanceCommands
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly EnrollmentService $enrollments,
    ) {}

    // ================================================================== tahsilat / kayıt

    public function collect(array $args, array $meta): Model
    {
        $student = Student::query()->withTrashed()->findOrFail($args['student']);
        $data = $args['data'];
        $data['installment_ids'] = array_values(array_filter($data['installment_ids'] ?? []));

        $notes = [];
        if ($data['installment_ids'] !== []) {
            $installments = Installment::query()->whereIn('id', $data['installment_ids'])->get();
            $open = $installments->whereIn('status', ['pending', 'partial', 'overdue'])
                ->reduce(fn ($s, Installment $i) => bcadd($s, $i->remaining(), 2), '0.00');
            if (bccomp(Money::of($data['amount']), $open, 2) > 0) {
                $notes[] = sprintf('Seçilen taksitlerin açık tutarı (%s TL) tahsilattan az: taksit çevrimdışıyken başka yerden ödenmiş olabilir. Tutar öğrencinin sonraki açık taksitlerine aktarıldı (artan kısım avans olarak duruyor).', Money::format($open));
                $data['overpayment'] = 'credit';
            }
            if (! empty($meta['base_time'])) {
                $others = DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
                    ->whereIn('pa.installment_id', $data['installment_ids'])->whereNull('p.voided_at')
                    ->where('p.created_at', '>', $meta['base_time'])
                    ->pluck('p.receipt_no')->unique()->values()->all();
                if ($others !== []) {
                    $notes[] = 'Aynı taksite cihaz çevrimdışıyken başka tahsilat da alındı: '.implode(', ', $others).'.';
                }
            }
        }
        // Tutar açık bakiyeyi aşarsa reddetme: para fiilen alındı → avans
        $data['overpayment'] = ($data['overpayment'] ?? 'reject') === 'reject' ? 'credit' : $data['overpayment'];

        $payment = $this->payments->collect($student, $data);

        if ($notes !== []) {
            ($meta['reconcile'])($payment, implode(' ', $notes));
        }

        return $payment;
    }

    public function void(array $args, array $meta): Model
    {
        $payment = Payment::query()->findOrFail($args['payment']);
        if ($payment->voided_at !== null) {
            return $payment;   // başka yerden zaten iptal edilmiş: idempotent
        }

        return $this->payments->void($payment, (string) $args['reason']);
    }

    public function cardDetails(array $args, array $meta): Model
    {
        $payment = Payment::query()->findOrFail($args['payment']);
        app(\App\Services\Finance\CardPaymentDetails::class)->override($payment, $args['rate'] ?? null, $args['installments'] ?? null);

        return $payment;
    }

    public function enroll(array $args, array $meta): Model
    {
        $student = Student::query()->withTrashed()->findOrFail($args['student']);

        return $this->enrollments->enroll($student, $args['data']);
    }

    // ================================================================== iade

    public function refund(array $args, array $meta): Model
    {
        $service = app(RefundService::class);
        $payment = Payment::query()->findOrFail($args['payment']);
        $data = $args['data'];
        if (! empty($data['idempotency_key']) && ($existing = Refund::query()->where('idempotency_key', $data['idempotency_key'])->first())) {
            return $existing;
        }
        $amount = Money::of($data['amount']);
        $refundable = $payment->voided_at ? '0.00' : RefundService::refundable($payment);
        if (bccomp($amount, $refundable, 2) <= 0) {
            return $service->refund($payment, $data);
        }

        // Para cihazda fiilen ödendi ama tahsilat sunucuda iptal edilmiş / başka yerden iade edilmiş
        $excess = bcsub($amount, $refundable, 2);
        $refund = null;
        if (Money::isPositive($refundable)) {
            $refund = $service->refund($payment, ['amount' => $refundable] + $data);
        }
        $entries = app(FinanceEntryService::class);
        $category = $entries->category('expense', 'sync_refund_excess', 'Mükerrer iade farkı (mutabakat)');
        $day = CarbonImmutable::parse($data['refunded_at'] ?? now());
        $entry = $entries->record([
            'direction' => 'expense', 'finance_category_id' => $category->id, 'finance_account_id' => (int) $data['finance_account_id'],
            'amount' => $excess, 'entry_date' => ($day->isFuture() ? CarbonImmutable::today() : $day)->toDateString(),
            'description' => sprintf('Çevrimdışı iade farkı — %s makbuzu (%s)', $payment->receipt_no, mb_substr((string) ($data['reason'] ?? ''), 0, 120)),
            'counterparty' => $data['payee_name'] ?? $payment->payer_name, 'document_no' => $payment->receipt_no,
        ]);
        $model = $refund ?? $entry;
        ($meta['reconcile'])($model, sprintf(
            'Cihazda %s makbuzundan %s TL iade ödendi ama sunucuda en çok %s TL iade edilebiliyordu (tahsilat %s). %s TL "Mükerrer iade farkı" gideri olarak kasadan düşüldü; veliden geri alın ya da tahsilatı düzeltin.',
            $payment->receipt_no, Money::format($amount), Money::format($refundable),
            $payment->voided_at ? 'iptal edilmiş' : 'başka yerden iade edilmiş', Money::format($excess),
        ));

        return $model;
    }

    public function refundVoid(array $args, array $meta): Model
    {
        $refund = Refund::query()->findOrFail($args['refund']);
        if ($refund->voided_at !== null) {
            return $refund;
        }

        return app(RefundService::class)->void($refund, (string) $args['reason']);
    }

    // ================================================================== gelir / gider

    public function entry(array $args, array $meta): Model
    {
        return app(FinanceEntryService::class)->record($args['data']);
    }

    public function entryVoid(array $args, array $meta): Model
    {
        $entry = FinanceEntry::query()->findOrFail($args['entry']);
        if ($entry->voided_at !== null) {
            return $entry;
        }

        return app(FinanceEntryService::class)->void($entry, (string) $args['reason']);
    }

    // ================================================================== aktarım

    public function transfer(array $args, array $meta): Model
    {
        return app(AccountService::class)->transfer((int) $args['from'], (int) $args['to'], (string) $args['amount'], (string) $args['date'], $args['description'] ?? null);
    }

    public function transferVoid(array $args, array $meta): Model
    {
        $transfer = AccountTransfer::query()->findOrFail($args['transfer']);
        if ($transfer->voided_at !== null) {
            return $transfer;
        }

        return app(AccountService::class)->voidTransfer($transfer, (string) $args['reason']);
    }

    // ================================================================== POS yatışı

    public function posSettle(array $args, array $meta): Model
    {
        $data = $args['data'];
        $data['detail_ids'] = array_values(array_filter($data['detail_ids'] ?? []));

        return app(ReconciliationService::class)->settle($data);
    }

    public function posVoid(array $args, array $meta): Model
    {
        $settlement = PosSettlement::query()->findOrFail($args['settlement']);
        if ($settlement->voided_at !== null) {
            return $settlement;
        }

        return app(ReconciliationService::class)->voidSettlement($settlement, (string) $args['reason']);
    }

    // ================================================================== senet

    /**
     * Senet kayıtları: taksit başına. Cihazın yeni verdiği senet numarası (basılı olabilir) taksit bazında korunur;
     * sunucuda o taksit için zaten geçerli senet varsa sunucudaki kalır, cihazınki silinir ve mutabakata düşer.
     */
    public function notesPrepare(array $args, array $meta): Model
    {
        $service = app(PromissoryNoteService::class);
        $ctx = app(SyncContext::class);
        $ids = array_values(array_filter((array) ($args['installments'] ?? [])));
        $map = (array) ($args['notes'] ?? []);
        $installments = Installment::query()->whereIn('id', $ids)->get()->sortBy(fn ($i) => array_search($i->id, $ids, true))->values();
        $first = null;
        foreach ($installments as $inst) {
            $m = $map[$inst->uuid] ?? null;
            if (! is_array($m) || count($m) < 2) {
                $notes = $service->prepare(collect([$inst]));
            } else {
                [$notes, $unused] = $ctx->withPresets(['promissory_notes' => [(string) $m[0]]], ['promissory_note' => [(string) $m[1]]],
                    fn () => $service->prepare(collect([$inst])));
                if (! empty($unused['promissory_notes'])) {
                    ($meta['unused'])('promissory_notes', $unused['promissory_notes']);
                    $current = $notes->first();
                    if ($current) {
                        ($meta['reconcile'])($current, sprintf('Cihaz bu taksit için %s numaralı senet düzenledi ama sunucuda %s numaralı geçerli senet zaten vardı; geçerli senet sunucudaki. Cihazda basılan senet varsa iptal edin.',
                            $m[1], $current->note_no));
                    }
                }
            }
            $first ??= $notes->first();
        }

        return $first ?? throw new \App\Sync\Server\SyncReject('Senet düzenlenecek geçerli taksit yok.', 'no_notes');
    }

    /** Basım kaydı (PDF sunucuda yeniden üretilmez; yalnız sayaç ve zaman). */
    public function notesPrinted(array $args, array $meta): Model
    {
        $ids = array_values(array_filter((array) ($args['notes'] ?? [])));
        $notes = PromissoryNote::query()->whereIn('id', $ids)->get();
        if ($notes->isEmpty()) {
            throw new \App\Sync\Server\SyncReject('Basılan senetler sunucuda bulunamadı.', 'missing_reference');
        }
        $at = CarbonImmutable::parse($args['at'] ?? now());
        $at = $at->isFuture() ? CarbonImmutable::now() : $at;
        PromissoryNote::query()->whereIn('id', $ids)->update([
            'print_count' => DB::raw('print_count + 1'),
            'first_printed_at' => DB::raw('COALESCE(first_printed_at, '.DB::getPdo()->quote($at->format('Y-m-d H:i:s')).')'),
            'last_printed_at' => $at->format('Y-m-d H:i:s'),
            'last_printed_by' => Auth::id(),
            'updated_at' => now(),
        ]);
        FinanceAudit::log('promissory_note.printed', sprintf('%d senet bastı (%s; çevrimdışı cihazdan).', $notes->count(), $notes->pluck('note_no')->take(5)->join(', ').($notes->count() > 5 ? '…' : '')));

        return $notes->first();
    }

    // ================================================================== tahsilat takibi

    public function collectionAdd(array $args, array $meta): Model
    {
        return app(CollectionService::class)->add($args['data']);
    }

    public function collectionStatus(array $args, array $meta): Model
    {
        $note = CollectionNote::query()->findOrFail($args['note']);

        return app(CollectionService::class)->setStatus($note, (string) $args['status'], isset($args['responsible_user_id']) ? (int) $args['responsible_user_id'] : null);
    }

    public function collectionReminders(array $args, array $meta): Model
    {
        $ids = array_values(array_filter((array) ($args['student_ids'] ?? [])));
        $before = (int) (CollectionNote::query()->max('id') ?? 0);
        app(CollectionService::class)->saveReminderDrafts($ids);

        return CollectionNote::query()->where('id', '>', $before)->orderBy('id')->first()
            ?? throw new \App\Sync\Server\SyncReject('Sunucuda gecikmiş borcu olan öğrenci kalmadı; taslak oluşmadı.', 'nothing_to_do');
    }

    // ================================================================== ödeme planı

    public function restructure(array $args, array $meta): Model
    {
        $enrollment = Enrollment::query()->findOrFail($args['enrollment']);
        $rows = array_map(fn ($r) => ['id' => $r['id'] ?? null, 'due_date' => (string) $r['due_date'], 'amount' => (string) $r['amount']], (array) ($args['rows'] ?? []));

        return app(InstallmentPlanService::class)->restructure($enrollment, $rows, $args['note'] ?? null);
    }

    public function adjustPrice(array $args, array $meta): Model
    {
        $enrollment = Enrollment::query()->findOrFail($args['enrollment']);

        return app(InstallmentPlanService::class)->adjustPrice($enrollment, (array) $args['data']);
    }
}
