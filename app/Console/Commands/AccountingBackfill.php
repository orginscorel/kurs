<?php

namespace App\Console\Commands;

use App\Models\AccountTransfer;
use App\Models\Branch;
use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\Accounting\AccountingPoster;
use App\Services\Accounting\ChartOfAccounts;
use App\Services\Accounting\LedgerReports;
use App\Services\Accounting\PeriodLock;
use App\Services\Finance\CardPaymentDetails;
use App\Support\BranchContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mevcut finans kayıtları için geriye dönük yevmiye fişi (ve kart tahsilatı komisyon ayrıntısı) üretir.
 * İdempotent: fişi olan kaynak atlanır. --dry-run tüm işlemi bir transaction içinde yapıp GERİ ALIR
 * (sayılar ve denge kontrolü gerçek çalıştırmayla aynıdır, veritabanına hiçbir şey kalmaz).
 * Kaynak kayıtlar (tahsilat, gider, transfer, hesap hareketi) DEĞİŞTİRİLMEZ.
 */
class AccountingBackfill extends Command
{
    protected $signature = 'kurs:accounting-backfill
        {--dry-run : Yalnız ne yapılacağını göster; hiçbir şey kaydetme}
        {--branch= : Yalnız bu şube}';

    protected $description = 'Mevcut tahsilat, gelir/gider, transfer, iade ve faturalar için geriye dönük yevmiye fişlerini üretir (idempotent)';

    public function handle(AccountingPoster $poster, ChartOfAccounts $chart, PeriodLock $periods, CardPaymentDetails $cards, LedgerReports $reports): int
    {
        $dry = (bool) $this->option('dry-run');
        AccountingPoster::resetState();
        if (! AccountingPoster::enabled()) {
            $this->error('Muhasebe tabloları yok ya da otomatik fiş ayarı kapalı (accounting.auto_journal).');

            return self::FAILURE;
        }

        $branches = Branch::query()->when($this->option('branch'), fn ($q, $b) => $q->whereKey($b))->get();
        foreach ($branches as $branch) {
            app(BranchContext::class)->run($branch->id, function () use ($branch, $dry, $poster, $chart, $periods, $cards, $reports) {
                $stats = ['created' => [], 'skipped' => 0, 'locked' => 0, 'cards' => 0];
                $before = DB::table('journal_entries')->where('branch_id', $branch->id)->count();

                DB::beginTransaction();
                try {
                    $chart->ensureDefaults($branch->id);
                    $events = $this->events($branch->id);
                    $this->info(sprintf('[%s] %d kaynak olay bulundu.', $branch->name, count($events)));

                    foreach ($events as [$date, $order, $type, $model]) {
                        if ($periods->isClosed($branch->id, $date)) {
                            $stats['locked']++;
                            continue;
                        }
                        $countBefore = DB::table('journal_entries')->where('branch_id', $branch->id)->count();
                        match ($type) {
                            'payment' => $poster->payment($model),
                            'payment_void' => $poster->paymentVoid($model),
                            'entry' => $poster->entry($model),
                            'entry_void' => $poster->entryVoid($model),
                            'transfer' => $poster->transfer($model),
                            'transfer_void' => $poster->transferVoid($model),
                            'refund' => $poster->refund($model),
                            'refund_void' => $poster->refundVoid($model),
                            'invoice' => $poster->invoiceIssued($model, (string) DB::table('invoice_payments')->where('invoice_id', $model->id)->sum('amount')),
                            'invoice_cancel' => $poster->invoiceCancelled($model),
                        };
                        if (DB::table('journal_entries')->where('branch_id', $branch->id)->count() > $countBefore) {
                            $stats['created'][$type] = ($stats['created'][$type] ?? 0) + 1;
                        } else {
                            $stats['skipped']++;
                        }
                        if ($type === 'payment' && ! $model->voided_at && $cards->capture($model)?->wasRecentlyCreated) {
                            $stats['cards']++;
                        }
                    }

                    $verify = $reports->verify($branch->id);
                    $tb = $reports->trialBalance($branch->id, CarbonImmutable::parse('2000-01-01'), CarbonImmutable::today()->addYears(5));
                    $dry ? DB::rollBack() : DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    $this->error("[{$branch->name}] HATA, hiçbir şey kaydedilmedi: ".$e->getMessage());

                    throw $e;
                }

                $this->table(['İşlem', 'Oluşturulan fiş'], collect($stats['created'])->map(fn ($c, $t) => [$t, $c])->values()->all());
                $this->line(sprintf('Atlanan (zaten fişi var): %d · Kapalı dönem nedeniyle atlanan: %d · Kart komisyon ayrıntısı: %d', $stats['skipped'], $stats['locked'], $stats['cards']));
                $this->line(sprintf('Fiş sayısı: önce %d → sonra %d%s', $before, $verify['entries'], $dry ? ' (deneme; geri alındı)' : ''));
                $this->line(sprintf('Mizan toplamı: borç %s / alacak %s → %s', $tb['totals']['total_debit'], $tb['totals']['total_credit'], $tb['balanced'] ? 'DENGEDE' : 'DENGESİZ!'));
                $this->line('Dengesiz fiş: '.count($verify['unbalanced']).' · başlık/satır uyuşmazlığı: '.count($verify['header_mismatch']));
                foreach ($verify['accounts'] as $a) {
                    $this->line(sprintf('  %s: hesap bakiyesi %s · muhasebe %s → %s', $a['name'], $a['balance'], $a['ledger'], $a['ok'] ? 'TUTUYOR' : 'FARK VAR'));
                }
            });
        }

        return self::SUCCESS;
    }

    /** @return list<array{0:string,1:int,2:string,3:mixed}> tarih, sıra, tür, model — kronolojik */
    private function events(int $branchId): array
    {
        $out = [];
        foreach (Payment::query()->where('branch_id', $branchId)->orderBy('id')->cursor() as $p) {
            $out[] = [$p->paid_at->toDateString(), 1, 'payment', $p];
            if ($p->voided_at) {
                $out[] = [$p->voided_at->toDateString(), 9, 'payment_void', $p];
            }
        }
        foreach (FinanceEntry::query()->where('branch_id', $branchId)->orderBy('id')->cursor() as $e) {
            $out[] = [$e->entry_date->toDateString(), 2, 'entry', $e];
            if ($e->voided_at) {
                $out[] = [$e->voided_at->toDateString(), 9, 'entry_void', $e];
            }
        }
        foreach (AccountTransfer::query()->where('branch_id', $branchId)->orderBy('id')->cursor() as $t) {
            $out[] = [$t->transfer_date->toDateString(), 0, 'transfer', $t];
            if ($t->voided_at) {
                $out[] = [$t->voided_at->toDateString(), 9, 'transfer_void', $t];
            }
        }
        foreach (Invoice::query()->where('branch_id', $branchId)->whereIn('status', ['issued', 'cancelled'])->whereNotNull('invoice_no')->orderBy('id')->cursor() as $i) {
            $out[] = [$i->issue_date->toDateString(), 3, 'invoice', $i];
            if ($i->cancelled_at) {
                $out[] = [$i->cancelled_at->toDateString(), 9, 'invoice_cancel', $i];
            }
        }
        foreach (Refund::query()->where('branch_id', $branchId)->orderBy('id')->cursor() as $r) {
            $out[] = [$r->refunded_at->toDateString(), 4, 'refund', $r];
            if ($r->voided_at) {
                $out[] = [$r->voided_at->toDateString(), 9, 'refund_void', $r];
            }
        }
        usort($out, fn ($a, $b) => [$a[0], $a[1], $a[3]->id] <=> [$b[0], $b[1], $b[3]->id]);

        return $out;
    }
}
