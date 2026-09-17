<?php

namespace App\Sync;

use App\Services\Finance\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Eşitlenmeyen türetilmiş alanları kaynak satırlardan yeniden hesaplar (her düğümde aynı formül):
 *  - finance_accounts.balance = Σ account_transactions.amount
 *  - installments.paid_amount = Σ tahsilat dağıtımı (iptal hariç) − Σ iade dağıtımı (iptal hariç), [0, tutar]
 *    installments.status = PaymentService::statusFor (iptal edilmiş taksit korunur)
 *  - products.stock = Σ stock_movements.quantity
 *  - exams.participant_count = sonuç sayısı
 * Yazma günlüğe girmez (bu alanlar eşitlenmez).
 *
 * @phpstan-type Report array<string, array{checked: int, drift: int, fixed: int}>
 */
class RecomputeService
{
    public const TARGETS = ['balances', 'installments', 'stock', 'exams'];

    /** Değişen tablo → yeniden hesaplanacak hedefler */
    public const TRIGGERS = [
        'account_transactions' => ['balances'], 'finance_accounts' => ['balances'],
        'payment_allocations' => ['installments'], 'refund_allocations' => ['installments'], 'installments' => ['installments'],
        'payments' => ['installments'], 'refunds' => ['installments'],
        'stock_movements' => ['stock'], 'products' => ['stock'],
        'exam_results' => ['exams'], 'exams' => ['exams'],
    ];

    public function __construct(private readonly SyncContext $context) {}

    /** @param list<string> $tables */
    public static function targetsFor(array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            foreach (self::TRIGGERS[$t] ?? [] as $target) {
                $out[$target] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<string> $targets
     * @return array<string, array{checked: int, drift: int, fixed: int}>
     */
    public function run(array $targets = self::TARGETS, bool $fix = true): array
    {
        $report = [];

        $this->context->withoutRecording(function () use ($targets, $fix, &$report) {
            $this->context->applying(function () use ($targets, $fix, &$report) {
                foreach (array_intersect(self::TARGETS, $targets) as $t) {
                    $report[$t] = $this->{$t}($fix);
                }
            });
        });

        return $report;
    }

    private function balances(bool $fix): array
    {
        $sums = DB::table('account_transactions')->selectRaw('finance_account_id AS id, COALESCE(SUM(amount), 0) AS s')
            ->groupBy('finance_account_id')->pluck('s', 'id');
        $r = ['checked' => 0, 'drift' => 0, 'fixed' => 0];
        foreach (DB::table('finance_accounts')->get(['id', 'balance']) as $a) {
            $r['checked']++;
            $want = bcadd('0', (string) ($sums[$a->id] ?? '0'), 2);
            if (bccomp((string) $a->balance, $want, 2) !== 0) {
                $r['drift']++;
                if ($fix) {
                    DB::table('finance_accounts')->where('id', $a->id)->update(['balance' => $want]);
                    $r['fixed']++;
                }
            }
        }

        return $r;
    }

    private function installments(bool $fix): array
    {
        $paid = DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereNull('p.voided_at')->selectRaw('pa.installment_id AS id, SUM(pa.amount) AS s')->groupBy('pa.installment_id')->pluck('s', 'id');
        $refunded = DB::table('refund_allocations as ra')->join('refunds as r', 'r.id', '=', 'ra.refund_id')
            ->whereNull('r.voided_at')->selectRaw('ra.installment_id AS id, SUM(ra.amount) AS s')->groupBy('ra.installment_id')->pluck('s', 'id');

        $r = ['checked' => 0, 'drift' => 0, 'fixed' => 0];
        DB::table('installments')->orderBy('id')->chunkById(1000, function ($rows) use ($paid, $refunded, $fix, &$r) {
            foreach ($rows as $i) {
                $r['checked']++;
                $p = bcsub((string) ($paid[$i->id] ?? '0'), (string) ($refunded[$i->id] ?? '0'), 2);
                if (bccomp($p, '0', 2) < 0) {
                    $p = '0.00';
                }
                if (bccomp($p, (string) $i->amount, 2) > 0) {
                    $p = bcadd((string) $i->amount, '0', 2);
                }
                $status = $i->status === 'cancelled' ? 'cancelled'
                    : PaymentService::statusFor((string) $i->amount, $p, CarbonImmutable::parse($i->due_date));
                if (bccomp((string) $i->paid_amount, $p, 2) !== 0 || $i->status !== $status) {
                    $r['drift']++;
                    if ($fix) {
                        DB::table('installments')->where('id', $i->id)->update(['paid_amount' => $p, 'status' => $status]);
                        $r['fixed']++;
                    }
                }
            }
        });

        return $r;
    }

    private function stock(bool $fix): array
    {
        $sums = DB::table('stock_movements')->selectRaw('product_id AS id, COALESCE(SUM(quantity), 0) AS s')->groupBy('product_id')->pluck('s', 'id');
        $r = ['checked' => 0, 'drift' => 0, 'fixed' => 0];
        foreach (DB::table('products')->get(['id', 'stock']) as $p) {
            $r['checked']++;
            $want = (int) ($sums[$p->id] ?? 0);
            if ((int) $p->stock !== $want) {
                $r['drift']++;
                if ($fix) {
                    DB::table('products')->where('id', $p->id)->update(['stock' => $want]);
                    $r['fixed']++;
                }
            }
        }

        return $r;
    }

    private function exams(bool $fix): array
    {
        $counts = DB::table('exam_results')->selectRaw('exam_id AS id, COUNT(*) AS c')->groupBy('exam_id')->pluck('c', 'id');
        $r = ['checked' => 0, 'drift' => 0, 'fixed' => 0];
        foreach (DB::table('exams')->get(['id', 'participant_count']) as $e) {
            $r['checked']++;
            $want = (int) ($counts[$e->id] ?? 0);
            if ((int) $e->participant_count !== $want) {
                $r['drift']++;
                if ($fix) {
                    DB::table('exams')->where('id', $e->id)->update(['participant_count' => $want]);
                    $r['fixed']++;
                }
            }
        }

        return $r;
    }
}
