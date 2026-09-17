<?php

namespace App\Services\Accounting;

use App\Models\AccountTransfer;
use App\Models\FinanceEntry;
use App\Models\Payment;
use App\Services\Finance\CardPaymentDetails;
use Illuminate\Database\Eloquent\Model;

/**
 * Mevcut finans servislerine dokunmadan otomatik yevmiye: tahsilat, gelir/gider ve hesap belgeleri
 * oluşturulduğunda/iptal edildiğinde aynı transaction içinde fiş yazılır. Fiş yazılamazsa (ör. kapalı dönem)
 * işlem bütünüyle geri alınır.
 */
class AccountingObserver
{
    public static function register(): void
    {
        Payment::created(fn (Payment $m) => self::run($m, fn (AccountingPoster $p) => $p->payment($m), fn () => app(CardPaymentDetails::class)->capture($m)));
        Payment::updated(fn (Payment $m) => $m->wasChanged('voided_at') && $m->voided_at ? self::run($m, fn (AccountingPoster $p) => $p->paymentVoid($m)) : null);
        FinanceEntry::created(fn (FinanceEntry $m) => self::run($m, fn (AccountingPoster $p) => $p->entry($m)));
        FinanceEntry::updated(fn (FinanceEntry $m) => $m->wasChanged('voided_at') && $m->voided_at ? self::run($m, fn (AccountingPoster $p) => $p->entryVoid($m)) : null);
        AccountTransfer::created(fn (AccountTransfer $m) => self::run($m, fn (AccountingPoster $p) => $p->transfer($m)));
        AccountTransfer::updated(fn (AccountTransfer $m) => $m->wasChanged('voided_at') && $m->voided_at ? self::run($m, fn (AccountingPoster $p) => $p->transferVoid($m)) : null);
    }

    private static function run(Model $model, callable $post, ?callable $after = null): void
    {
        if (! AccountingPoster::enabled($model->branch_id)) {
            return;
        }
        $post(app(AccountingPoster::class));
        if ($after) {
            $after();
        }
    }
}
