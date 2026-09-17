<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Services\Finance\ReceivableAging;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rapor Merkezi finans analizleri. Her rapor aynı biçimde döner (ekran, Excel ve PDF ortak kullanır):
 *   {key, title, description, notes[], kpis[], chart?, tables:[{title, columns:[{key,label,type}], rows[], totals?}]}
 * Tutarlar string (bcmath). İptal edilmiş kayıtlar hiçbir toplamda yoktur.
 */
class FinanceAnalytics
{
    public const REPORTS = [
        'income-statement' => 'Gelir tablosu (aylık)',
        'cash-flow' => 'Nakit akışı',
        'collection-performance' => 'Tahsilat performansı',
        'aging' => 'Alacak yaşlandırma',
        'invoice-vat' => 'Fatura ve KDV özeti',
        'program-profitability' => 'Program bazlı kârlılık',
        'trial-balance' => 'Mizan',
    ];

    public function __construct(private readonly LedgerReports $ledger) {}

    public function run(string $key, int $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($to->lt($from)) {
            throw new BusinessRuleException('Bitiş tarihi başlangıçtan önce olamaz.', 'invalid_range');
        }
        if ($from->diffInDays($to) > 1850) {
            throw new BusinessRuleException('Rapor aralığı en fazla 5 yıl olabilir.', 'range_too_long');
        }
        $data = match ($key) {
            'income-statement' => $this->incomeStatement($branchId, $from, $to),
            'cash-flow' => $this->cashFlow($branchId, $from, $to),
            'collection-performance' => $this->collectionPerformance($branchId, $from, $to),
            'aging' => $this->aging($branchId),
            'invoice-vat' => $this->invoiceVat($branchId, $from, $to),
            'program-profitability' => $this->programProfitability($branchId, $from, $to),
            'trial-balance' => $this->trialBalance($branchId, $from, $to),
            default => throw new BusinessRuleException('Rapor bulunamadı.', 'report_not_found', [], 404),
        };

        return ['key' => $key, 'title' => self::REPORTS[$key], 'from' => $from->toDateString(), 'to' => $to->toDateString()] + $data;
    }

    // ------------------------------------------------------------------ yardımcılar

    /** @return array<string, array{period:string, label:string}> */
    private function months(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        for ($c = $from->startOfMonth(); $c->lte($to); $c = $c->addMonthNoOverflow()) {
            $out[$c->format('Y-m')] = ['period' => $c->format('Y-m'), 'label' => $c->locale('tr')->translatedFormat('F Y')];
        }

        return $out;
    }

    private static function d(mixed $v): string
    {
        return Dec::round(Dec::norm($v));
    }

    private static function sumInto(array &$rows, string $period, string $field, string $amount): void
    {
        if (isset($rows[$period])) {
            $rows[$period][$field] = bcadd($rows[$period][$field], $amount, 2);
        }
    }

    private static function col(string $key, string $label, string $type = 'money'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    private static function totalsOf(array $rows, array $moneyKeys, string $labelKey = 'label'): array
    {
        $t = [$labelKey => 'Toplam'];
        foreach ($moneyKeys as $k) {
            $t[$k] = array_reduce($rows, fn ($s, $r) => bcadd($s, (string) ($r[$k] ?? '0'), 2), '0.00');
        }

        return $t;
    }

    private static function pct(string $part, string $whole): ?string
    {
        if (! Dec::positive($whole)) {
            return null;
        }

        return Dec::round(Dec::div(Dec::mul($part, '100'), $whole), 1);
    }

    // ------------------------------------------------------------------ 1. gelir tablosu

    private function incomeStatement(int $b, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->months($from, $to);
        $rows = [];
        foreach ($months as $p => $m) {
            $rows[$p] = $m + ['collections' => '0.00', 'refunds' => '0.00', 'other_income' => '0.00', 'revenue' => '0.00', 'expense' => '0.00', 'net' => '0.00'];
        }
        [$f, $t] = [$from->startOfDay(), $to->endOfDay()];

        foreach (DB::table('payments')->where('branch_id', $b)->whereNull('voided_at')->whereBetween('paid_at', [$f, $t])
            ->selectRaw('DATE(paid_at) AS d, SUM(amount) AS s')->groupBy('d')->get() as $r) {
            self::sumInto($rows, substr($r->d, 0, 7), 'collections', self::d($r->s));
        }
        foreach (DB::table('refunds')->where('branch_id', $b)->whereNull('voided_at')->whereBetween('refunded_at', [$f, $t])
            ->selectRaw('DATE(refunded_at) AS d, SUM(amount) AS s')->groupBy('d')->get() as $r) {
            self::sumInto($rows, substr($r->d, 0, 7), 'refunds', self::d($r->s));
        }
        $byCat = [];
        foreach (DB::table('finance_entries as e')->join('finance_categories as c', 'c.id', '=', 'e.finance_category_id')
            ->where('e.branch_id', $b)->whereNull('e.voided_at')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('e.entry_date AS d, e.direction, c.name, SUM(e.amount) AS s')->groupBy('d', 'e.direction', 'c.name')->get() as $r) {
            self::sumInto($rows, substr((string) $r->d, 0, 7), $r->direction === 'income' ? 'other_income' : 'expense', self::d($r->s));
            $k = $r->direction.'|'.$r->name;
            $byCat[$k] ??= ['direction' => $r->direction === 'income' ? 'Gelir' : 'Gider', 'label' => $r->name, 'amount' => '0.00'];
            $byCat[$k]['amount'] = bcadd($byCat[$k]['amount'], self::d($r->s), 2);
        }
        foreach ($rows as &$r) {
            $r['revenue'] = bcadd(bcsub($r['collections'], $r['refunds'], 2), $r['other_income'], 2);
            $r['net'] = bcsub($r['revenue'], $r['expense'], 2);
        }
        unset($r);
        $rows = array_values($rows);
        $totals = self::totalsOf($rows, ['collections', 'refunds', 'other_income', 'revenue', 'expense', 'net']);
        $cats = array_values($byCat);
        usort($cats, fn ($a, $b) => strcmp($a['direction'], $b['direction']) ?: bccomp($b['amount'], $a['amount'], 2));

        // Muhasebe (fatura esaslı): yevmiyeden gelir/gider sınıfları
        $acc = DB::table('journal_lines')->where('branch_id', $b)->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->where('ledger_code', 'like', '6%')->orWhere('ledger_code', 'like', '7%'))
            ->groupBy('ledger_code')->selectRaw('ledger_code, SUM(debit) AS d, SUM(credit) AS c')->orderBy('ledger_code')->get();
        $chart = app(ChartOfAccounts::class)->accounts($b);
        $accRows = [];
        foreach ($acc as $r) {
            $accRows[] = ['label' => $r->ledger_code.' '.($chart[$r->ledger_code]['name'] ?? ''), 'amount' => bcsub(self::d($r->c), self::d($r->d), 2)];
        }
        $accNet = array_reduce($accRows, fn ($s, $r) => bcadd($s, $r['amount'], 2), '0.00');

        return [
            'description' => 'Aylık gelir, gider ve net sonuç. Üst tablo nakit esaslıdır (tahsilat − iade + diğer gelir − gider); alt tablo yevmiye fişlerinden (fatura esaslı) hesaplanır.',
            'notes' => [
                'Nakit esaslı tablo yönetim raporudur; resmi gelir tablosu için muhasebecinizin tahakkuk esaslı kayıtları esas alınır.',
                'Fatura esaslı tabloda öğrenci tahsilatları fatura kesilene kadar "alınan avans" (340) hesabında bekler, gelire yansımaz.',
                'Gelir/gider kayıtları KDV ayrıştırılmadan brüt tutarla yazılır.',
            ],
            'kpis' => [
                ['label' => 'Net gelir', 'value' => $totals['revenue'], 'type' => 'money'],
                ['label' => 'Gider', 'value' => $totals['expense'], 'type' => 'money'],
                ['label' => 'Net sonuç', 'value' => $totals['net'], 'type' => 'money', 'tone' => bccomp($totals['net'], '0', 2) < 0 ? 'danger' : 'success'],
                ['label' => 'Kâr marjı', 'value' => self::pct($totals['net'], $totals['revenue']), 'type' => 'percent'],
            ],
            'chart' => ['type' => 'bar', 'x' => 'label', 'series' => [['key' => 'revenue', 'label' => 'Net gelir'], ['key' => 'expense', 'label' => 'Gider']]],
            'tables' => [
                ['title' => 'Aylık sonuç (nakit esaslı)', 'columns' => [self::col('label', 'Ay', 'text'), self::col('collections', 'Öğrenci tahsilatı'), self::col('refunds', 'İadeler (−)'),
                    self::col('other_income', 'Diğer gelir'), self::col('revenue', 'Net gelir'), self::col('expense', 'Gider'), self::col('net', 'Net sonuç')], 'rows' => $rows, 'totals' => $totals, 'chart' => true],
                ['title' => 'Kategori dağılımı', 'columns' => [self::col('direction', 'Tür', 'text'), self::col('label', 'Kategori', 'text'), self::col('amount', 'Tutar')], 'rows' => $cats],
                ['title' => 'Muhasebe gelir tablosu (yevmiye, fatura esaslı)', 'columns' => [self::col('label', 'Hesap', 'text'), self::col('amount', 'Tutar (gelir +, gider −)')],
                    'rows' => $accRows, 'totals' => ['label' => 'Dönem net', 'amount' => $accNet]],
            ],
        ];
    }

    // ------------------------------------------------------------------ 2. nakit akışı

    private function cashFlow(int $b, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->months($from, $to);
        $rows = [];
        foreach ($months as $p => $m) {
            $rows[$p] = $m + ['opening' => '0.00', 'collections' => '0.00', 'other_income' => '0.00', 'refunds' => '0.00', 'expenses' => '0.00', 'other' => '0.00', 'net' => '0.00', 'closing' => '0.00'];
        }
        $f = $from->startOfMonth()->startOfDay();
        $opening = self::d(DB::table('account_transactions')->where('branch_id', $b)->where('occurred_at', '<', $f)->sum('amount'));

        $q = DB::table('account_transactions as t')
            ->leftJoin('finance_entries as fe', fn ($j) => $j->on('fe.id', '=', 't.source_id')->where('t.source_type', '=', 'finance_entry'))
            ->leftJoin('account_transfers as at', fn ($j) => $j->on('at.id', '=', 't.source_id')->where('t.source_type', '=', 'account_transfer'))
            ->where('t.branch_id', $b)->whereBetween('t.occurred_at', [$f, $to->endOfDay()])
            ->selectRaw("DATE(t.occurred_at) AS d, t.source_type, fe.direction, at.kind, SUM(t.amount) AS s")
            ->groupBy('d', 't.source_type', 'fe.direction', 'at.kind')->get();
        foreach ($q as $r) {
            $amount = self::d($r->s);
            $field = match (true) {
                $r->source_type === 'payment' => 'collections',
                $r->source_type === 'refund' => 'refunds',
                $r->source_type === 'finance_entry' && $r->direction === 'income' => 'other_income',
                $r->source_type === 'finance_entry' => 'expenses',
                default => 'other',   // transfer (toplamda sıfır), açılış, sayım farkı
            };
            self::sumInto($rows, substr($r->d, 0, 7), $field, $amount);
        }
        $running = $opening;
        foreach ($rows as &$r) {
            $r['opening'] = $running;
            $r['net'] = Dec::add($r['collections'], $r['other_income'], $r['refunds'], $r['expenses'], $r['other']);
            $running = bcadd($running, $r['net'], 2);
            $r['closing'] = $running;
        }
        unset($r);
        $rows = array_values($rows);
        $totals = self::totalsOf($rows, ['collections', 'other_income', 'refunds', 'expenses', 'other', 'net']);
        $totals['opening'] = $opening;
        $totals['closing'] = $running;

        $accounts = DB::table('finance_accounts')->where('branch_id', $b)->whereNull('deleted_at')->orderByRaw("FIELD(kind, 'cash', 'pos', 'bank')")->get(['name', 'kind', 'balance'])
            ->map(fn ($a) => ['label' => $a->name, 'kind' => ['cash' => 'Kasa', 'bank' => 'Banka', 'pos' => 'POS'][$a->kind] ?? $a->kind, 'balance' => self::d($a->balance)])->all();

        return [
            'description' => 'Kasa, banka ve POS hesaplarına giren ve çıkan para (hesap hareketlerinden). Hesaplar arası transferler toplamda sıfırlanır.',
            'notes' => ['Giriş pozitif, çıkış negatif gösterilir. "Diğer" sütunu açılış bakiyesi, sayım farkı ve hesaplar arası transferin netidir.', 'Kapanış bakiyesi, kasa/banka hesaplarının toplam bakiyesiyle aynıdır.'],
            'kpis' => [
                ['label' => 'Dönem başı nakit', 'value' => $opening, 'type' => 'money'],
                ['label' => 'Net nakit akışı', 'value' => $totals['net'], 'type' => 'money', 'tone' => bccomp($totals['net'], '0', 2) < 0 ? 'danger' : 'success'],
                ['label' => 'Dönem sonu nakit', 'value' => $running, 'type' => 'money'],
            ],
            'chart' => ['type' => 'bar', 'x' => 'label', 'series' => [['key' => 'net', 'label' => 'Net akış']]],
            'tables' => [
                ['title' => 'Aylık nakit akışı', 'columns' => [self::col('label', 'Ay', 'text'), self::col('opening', 'Dönem başı'), self::col('collections', 'Tahsilat'), self::col('other_income', 'Diğer gelir'),
                    self::col('refunds', 'İade'), self::col('expenses', 'Gider'), self::col('other', 'Diğer'), self::col('net', 'Net'), self::col('closing', 'Dönem sonu')], 'rows' => $rows, 'totals' => $totals, 'chart' => true],
                ['title' => 'Güncel hesap bakiyeleri', 'columns' => [self::col('label', 'Hesap', 'text'), self::col('kind', 'Tür', 'text'), self::col('balance', 'Bakiye')], 'rows' => $accounts,
                    'totals' => ['label' => 'Toplam', 'kind' => '', 'balance' => array_reduce($accounts, fn ($s, $a) => bcadd($s, $a['balance'], 2), '0.00')]],
            ],
        ];
    }

    // ------------------------------------------------------------------ 3. tahsilat performansı

    private function collectionPerformance(int $b, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->months($from, $to);
        $rows = [];
        foreach ($months as $p => $m) {
            $rows[$p] = $m + ['planned' => '0.00', 'paid_on_due' => '0.00', 'open' => '0.00', 'rate' => null, 'collected' => '0.00', 'count' => 0];
        }
        foreach (DB::table('installments')->where('branch_id', $b)->where('status', '!=', 'cancelled')->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('due_date AS d, SUM(amount) AS a, SUM(paid_amount) AS p')->groupBy('d')->get() as $r) {
            $p = substr((string) $r->d, 0, 7);
            self::sumInto($rows, $p, 'planned', self::d($r->a));
            self::sumInto($rows, $p, 'paid_on_due', self::d($r->p));
        }
        foreach (DB::table('payments')->where('branch_id', $b)->whereNull('voided_at')->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(paid_at) AS d, SUM(amount) AS s, COUNT(*) AS c')->groupBy('d')->get() as $r) {
            $p = substr($r->d, 0, 7);
            self::sumInto($rows, $p, 'collected', self::d($r->s));
            if (isset($rows[$p])) {
                $rows[$p]['count'] += (int) $r->c;
            }
        }
        foreach ($rows as &$r) {
            $r['open'] = bcsub($r['planned'], $r['paid_on_due'], 2);
            $r['rate'] = self::pct($r['paid_on_due'], $r['planned']);
        }
        unset($r);
        $rows = array_values($rows);
        $totals = self::totalsOf($rows, ['planned', 'paid_on_due', 'open', 'collected']);
        $totals['rate'] = self::pct($totals['paid_on_due'], $totals['planned']);
        $totals['count'] = array_sum(array_column($rows, 'count'));

        $byProgram = DB::table('installments as i')->join('enrollments as e', 'e.id', '=', 'i.enrollment_id')->leftJoin('programs as p', 'p.id', '=', 'e.program_id')
            ->where('i.branch_id', $b)->where('i.status', '!=', 'cancelled')->whereBetween('i.due_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('p.id', 'p.name')->selectRaw('p.name, SUM(i.amount) AS a, SUM(i.paid_amount) AS pd, COUNT(DISTINCT i.student_id) AS students')->orderByDesc('a')->get()
            ->map(fn ($r) => ['label' => $r->name ?? 'Programsız', 'students' => (int) $r->students, 'planned' => self::d($r->a), 'paid_on_due' => self::d($r->pd),
                'open' => bcsub(self::d($r->a), self::d($r->pd), 2), 'rate' => self::pct(self::d($r->pd), self::d($r->a))])->all();

        return [
            'description' => 'Vadesi dönem içinde olan taksitler (planlanan) ve bunların ne kadarının ödendiği (gerçekleşen); ayrıca dönemde alınan toplam tahsilat.',
            'notes' => ['Tahsilat oranı = vadesi dönemde olan taksitlerden bugüne kadar ödenen / planlanan.', '"Alınan tahsilat" ödeme tarihine göredir; erken ya da geç ödenen taksitleri de içerir.'],
            'kpis' => [
                ['label' => 'Planlanan', 'value' => $totals['planned'], 'type' => 'money'],
                ['label' => 'Gerçekleşen', 'value' => $totals['paid_on_due'], 'type' => 'money'],
                ['label' => 'Tahsilat oranı', 'value' => $totals['rate'], 'type' => 'percent'],
                ['label' => 'Açık kalan', 'value' => $totals['open'], 'type' => 'money', 'tone' => Dec::positive($totals['open']) ? 'warning' : null],
            ],
            'chart' => ['type' => 'bar', 'x' => 'label', 'series' => [['key' => 'planned', 'label' => 'Planlanan'], ['key' => 'paid_on_due', 'label' => 'Gerçekleşen']]],
            'tables' => [
                ['title' => 'Aylık planlanan / gerçekleşen', 'columns' => [self::col('label', 'Ay', 'text'), self::col('planned', 'Planlanan'), self::col('paid_on_due', 'Gerçekleşen'),
                    self::col('open', 'Açık'), self::col('rate', 'Oran', 'percent'), self::col('collected', 'Alınan tahsilat'), self::col('count', 'Tahsilat adedi', 'number')], 'rows' => $rows, 'totals' => $totals, 'chart' => true],
                ['title' => 'Program bazında', 'columns' => [self::col('label', 'Program', 'text'), self::col('students', 'Öğrenci', 'number'), self::col('planned', 'Planlanan'),
                    self::col('paid_on_due', 'Gerçekleşen'), self::col('open', 'Açık'), self::col('rate', 'Oran', 'percent')], 'rows' => $byProgram],
            ],
        ];
    }

    // ------------------------------------------------------------------ 4. yaşlandırma

    private function aging(int $b): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $open = DB::table('installments as i')->join('students as s', 's.id', '=', 'i.student_id')
            ->where('i.branch_id', $b)->whereIn('i.status', ['pending', 'partial', 'overdue'])->whereNull('s.deleted_at');
        $summary = ReceivableAging::summarize((clone $open)->get(['i.due_date', DB::raw('i.amount - i.paid_amount AS remaining')]));
        $buckets = array_map(fn ($x) => ['label' => $x['label'], 'count' => $x['count'], 'amount' => $x['amount'], 'share' => self::pct($x['amount'], $summary['total'])], array_values($summary['buckets']));

        $students = (clone $open)->groupBy('i.student_id', 's.full_name', 's.student_no')
            ->selectRaw('s.full_name, s.student_no, SUM(i.amount - i.paid_amount) AS remaining, MIN(CASE WHEN i.due_date < ? THEN i.due_date END) AS oldest,'
                .implode(',', array_map(fn ($k) => ReceivableAging::sqlSum($k, 'i.due_date', 'i.amount - i.paid_amount', $today)." AS {$k}", array_keys(ReceivableAging::BUCKETS))), [$today])
            ->havingRaw('SUM(CASE WHEN i.due_date < ? THEN i.amount - i.paid_amount ELSE 0 END) > 0', [$today])
            ->orderByRaw('SUM(CASE WHEN i.due_date < ? THEN i.amount - i.paid_amount ELSE 0 END) DESC', [$today])->limit(100)->get()
            ->map(fn ($r) => ['label' => $r->full_name, 'student_no' => $r->student_no, 'days' => $r->oldest ? max(0, ReceivableAging::daysOverdue($r->oldest)) : 0,
                'd0_30' => self::d($r->d0_30), 'd31_60' => self::d($r->d31_60), 'd61_90' => self::d($r->d61_90), 'd90_plus' => self::d($r->d90_plus),
                'overdue' => Dec::add(self::d($r->d0_30), self::d($r->d31_60), self::d($r->d61_90), self::d($r->d90_plus)), 'remaining' => self::d($r->remaining)])->all();

        return [
            'from' => $today, 'to' => $today,
            'description' => 'Bugün itibarıyla açık taksitlerin vadeden bu yana geçen güne göre dağılımı (0-30 / 31-60 / 61-90 / 90+).',
            'notes' => ['Tarih filtresi bu raporu etkilemez; her zaman bugünkü durumu gösterir.', 'Öğrenci listesi en yüksek gecikmiş bakiyeli 100 öğrenciyle sınırlıdır.'],
            'kpis' => [
                ['label' => 'Toplam alacak', 'value' => $summary['total'], 'type' => 'money'],
                ['label' => 'Gecikmiş', 'value' => $summary['overdue'], 'type' => 'money', 'tone' => Dec::positive($summary['overdue']) ? 'danger' : null],
                ['label' => '90+ gün', 'value' => $summary['buckets']['d90_plus']['amount'], 'type' => 'money', 'tone' => Dec::positive($summary['buckets']['d90_plus']['amount']) ? 'danger' : null],
                ['label' => 'Gecikmiş oranı', 'value' => self::pct($summary['overdue'], $summary['total']), 'type' => 'percent'],
            ],
            'chart' => ['type' => 'bar', 'x' => 'label', 'table' => 0, 'series' => [['key' => 'amount', 'label' => 'Tutar']]],
            'tables' => [
                ['title' => 'Yaşlandırma kovaları', 'columns' => [self::col('label', 'Gecikme', 'text'), self::col('count', 'Taksit', 'number'), self::col('amount', 'Tutar'), self::col('share', 'Pay', 'percent')],
                    'rows' => $buckets, 'totals' => ['label' => 'Toplam', 'count' => array_sum(array_column($buckets, 'count')), 'amount' => $summary['total'], 'share' => null], 'chart' => true],
                ['title' => 'Gecikmiş bakiyesi olan öğrenciler', 'columns' => [self::col('label', 'Öğrenci', 'text'), self::col('student_no', 'No', 'text'), self::col('days', 'En eski gecikme (gün)', 'number'),
                    self::col('d0_30', '0-30'), self::col('d31_60', '31-60'), self::col('d61_90', '61-90'), self::col('d90_plus', '90+'), self::col('overdue', 'Gecikmiş'), self::col('remaining', 'Toplam kalan')],
                    'rows' => $students, 'totals' => self::totalsOf($students, ['d0_30', 'd31_60', 'd61_90', 'd90_plus', 'overdue', 'remaining']) + ['student_no' => '', 'days' => null]],
            ],
        ];
    }

    // ------------------------------------------------------------------ 5. fatura / KDV

    private function invoiceVat(int $b, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->months($from, $to);
        $rows = [];
        foreach ($months as $p => $m) {
            $rows[$p] = $m + ['count' => 0, 'net' => '0.00', 'vat' => '0.00', 'withholding' => '0.00', 'total' => '0.00', 'returns' => '0.00'];
        }
        $inv = DB::table('invoices')->where('branch_id', $b)->where('status', 'issued')->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->get(['id', 'kind', 'issue_date', 'net_total', 'vat_total', 'withholding_total', 'payable_total']);
        foreach ($inv as $i) {
            $p = substr((string) $i->issue_date, 0, 7);
            if (! isset($rows[$p])) {
                continue;
            }
            $sign = $i->kind === 'return' ? '-1' : '1';
            $rows[$p]['count']++;
            foreach (['net' => 'net_total', 'vat' => 'vat_total', 'withholding' => 'withholding_total', 'total' => 'payable_total'] as $k => $col) {
                $rows[$p][$k] = bcadd($rows[$p][$k], bcmul(self::d($i->{$col}), $sign, 2), 2);
            }
            if ($i->kind === 'return') {
                $rows[$p]['returns'] = bcadd($rows[$p]['returns'], self::d($i->payable_total), 2);
            }
        }
        $rows = array_values($rows);
        $totals = self::totalsOf($rows, ['net', 'vat', 'withholding', 'total', 'returns']);
        $totals['count'] = array_sum(array_column($rows, 'count'));

        $rates = DB::table('invoice_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')
            ->where('i.branch_id', $b)->where('i.status', 'issued')->whereBetween('i.issue_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('l.vat_rate', 'i.kind')->selectRaw('l.vat_rate, i.kind, SUM(l.net_amount) AS n, SUM(l.vat_amount) AS v, SUM(l.withholding_amount) AS w')->get();
        $byRate = [];
        foreach ($rates as $r) {
            $k = rtrim(rtrim(self::d($r->vat_rate), '0'), '.');
            $sign = $r->kind === 'return' ? '-1' : '1';
            $byRate[$k] ??= ['label' => '%'.$k, 'net' => '0.00', 'vat' => '0.00', 'withholding' => '0.00'];
            $byRate[$k]['net'] = bcadd($byRate[$k]['net'], bcmul(self::d($r->n), $sign, 2), 2);
            $byRate[$k]['vat'] = bcadd($byRate[$k]['vat'], bcmul(self::d($r->v), $sign, 2), 2);
            $byRate[$k]['withholding'] = bcadd($byRate[$k]['withholding'], bcmul(self::d($r->w), $sign, 2), 2);
        }
        ksort($byRate, SORT_NATURAL);
        $byRate = array_values($byRate);
        foreach ($byRate as &$r) {
            $r['declared'] = bcsub($r['vat'], $r['withholding'], 2);
        }
        unset($r);

        $unbilled = DB::table('payments as p')
            ->leftJoinSub(DB::table('invoice_payments as ip')->join('invoices as i', 'i.id', '=', 'ip.invoice_id')->where('i.status', 'issued')->where('i.kind', 'sales')
                ->groupBy('ip.payment_id')->selectRaw('ip.payment_id, SUM(ip.amount) AS linked'), 'l', 'l.payment_id', '=', 'p.id')
            ->leftJoinSub(DB::table('refunds')->whereNull('voided_at')->groupBy('payment_id')->selectRaw('payment_id, SUM(amount - invoiced_portion) AS r'), 'r', 'r.payment_id', '=', 'p.id')
            ->where('p.branch_id', $b)->whereNull('p.voided_at')->whereBetween('p.paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('COALESCE(SUM(GREATEST(p.amount - COALESCE(l.linked, 0) - COALESCE(r.r, 0), 0)), 0) AS s')->value('s');

        return [
            'description' => 'Kesilen satış ve iade faturalarının aylık matrah, KDV ve tevkifat toplamları; oran bazında KDV dökümü.',
            'notes' => [
                'İade faturaları eksi olarak düşülür. İptal edilen ve taslak faturalar dahil değildir.',
                'Bu özet beyanname hazırlığına yardımcıdır; KDV oranları ve beyan tutarları kurum muhasebecisiyle teyit edilmelidir.',
                'e-Fatura/e-Arşiv entegratörü bağlı değildir; faturalar GİB\'e iletilmemiştir.',
            ],
            'kpis' => [
                ['label' => 'Matrah', 'value' => $totals['net'], 'type' => 'money'],
                ['label' => 'Hesaplanan KDV', 'value' => $totals['vat'], 'type' => 'money'],
                ['label' => 'Fatura adedi', 'value' => (string) $totals['count'], 'type' => 'number'],
                ['label' => 'Dönemde faturalanmamış tahsilat', 'value' => self::d($unbilled), 'type' => 'money', 'tone' => Dec::positive(self::d($unbilled)) ? 'warning' : null],
            ],
            'chart' => ['type' => 'bar', 'x' => 'label', 'series' => [['key' => 'net', 'label' => 'Matrah'], ['key' => 'vat', 'label' => 'KDV']]],
            'tables' => [
                ['title' => 'Aylık fatura özeti', 'columns' => [self::col('label', 'Ay', 'text'), self::col('count', 'Adet', 'number'), self::col('net', 'Matrah'), self::col('vat', 'KDV'),
                    self::col('withholding', 'Tevkifat'), self::col('returns', 'İade faturası'), self::col('total', 'Ödenecek toplam')], 'rows' => $rows, 'totals' => $totals, 'chart' => true],
                ['title' => 'KDV oranı dökümü', 'columns' => [self::col('label', 'Oran', 'text'), self::col('net', 'Matrah'), self::col('vat', 'KDV'), self::col('withholding', 'Tevkifat'), self::col('declared', 'Beyan edilecek KDV')],
                    'rows' => $byRate, 'totals' => self::totalsOf($byRate, ['net', 'vat', 'withholding', 'declared'])],
            ],
        ];
    }

    // ------------------------------------------------------------------ 6. program kârlılığı

    private function programProfitability(int $b, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$f, $t] = [$from->startOfDay(), $to->endOfDay()];
        $programs = DB::table('programs')->where('branch_id', $b)->pluck('name', 'id');
        $rev = [];
        foreach (DB::table('payments as p')->leftJoin('enrollments as e', 'e.id', '=', 'p.enrollment_id')->where('p.branch_id', $b)->whereNull('p.voided_at')
            ->whereBetween('p.paid_at', [$f, $t])->groupBy('e.program_id')->selectRaw('e.program_id AS pid, SUM(p.amount) AS s, COUNT(DISTINCT p.student_id) AS st')->get() as $r) {
            $rev[(int) $r->pid] = ['collections' => self::d($r->s), 'students' => (int) $r->st, 'refunds' => '0.00'];
        }
        foreach (DB::table('refunds as r')->join('payments as p', 'p.id', '=', 'r.payment_id')->leftJoin('enrollments as e', 'e.id', '=', 'p.enrollment_id')
            ->where('r.branch_id', $b)->whereNull('r.voided_at')->whereBetween('r.refunded_at', [$f, $t])->groupBy('e.program_id')->selectRaw('e.program_id AS pid, SUM(r.amount) AS s')->get() as $r) {
            $rev[(int) $r->pid] ??= ['collections' => '0.00', 'students' => 0, 'refunds' => '0.00'];
            $rev[(int) $r->pid]['refunds'] = self::d($r->s);
        }
        // Verilen ders saati (dakika, tam sayı): iptal edilmeyen oturumlar
        $minutes = [];
        foreach (DB::table('lesson_sessions as ls')->join('class_groups as cg', 'cg.id', '=', 'ls.class_group_id')
            ->where('ls.branch_id', $b)->where('ls.status', '!=', 'cancelled')->whereBetween('ls.date', [$from->toDateString(), $to->toDateString()])
            ->get(['cg.program_id', 'ls.starts_at', 'ls.ends_at']) as $s) {
            $m = max(0, (int) round((strtotime((string) $s->ends_at) - strtotime((string) $s->starts_at)) / 60));
            $minutes[(int) $s->program_id] = ($minutes[(int) $s->program_id] ?? 0) + $m;
        }
        $totalMinutes = array_sum($minutes);

        $exp = DB::table('finance_entries as e')->join('finance_categories as c', 'c.id', '=', 'e.finance_category_id')
            ->where('e.branch_id', $b)->where('e.direction', 'expense')->whereNull('e.voided_at')->whereBetween('e.entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("COALESCE(SUM(CASE WHEN c.code = 'salary' THEN e.amount ELSE 0 END), 0) AS salary, COALESCE(SUM(CASE WHEN c.code <> 'salary' THEN e.amount ELSE 0 END), 0) AS other")->first();
        $salary = self::d($exp->salary);
        $other = self::d($exp->other);

        $ids = array_unique(array_merge(array_keys($rev), array_keys($minutes)));
        $totalRevenue = '0.00';
        foreach ($rev as $r) {
            $totalRevenue = bcadd($totalRevenue, bcsub($r['collections'], $r['refunds'], 2), 2);
        }
        $rows = [];
        foreach ($ids as $pid) {
            $r = $rev[$pid] ?? ['collections' => '0.00', 'students' => 0, 'refunds' => '0.00'];
            $revenue = bcsub($r['collections'], $r['refunds'], 2);
            $min = $minutes[$pid] ?? 0;
            $teacher = $totalMinutes > 0 ? Dec::round(Dec::div(Dec::mul($salary, (string) $min), (string) $totalMinutes)) : '0.00';
            $overhead = Dec::positive($totalRevenue) ? Dec::round(Dec::div(Dec::mul($other, $revenue), $totalRevenue)) : '0.00';
            $profit = bcsub(bcsub($revenue, $teacher, 2), $overhead, 2);
            $rows[] = [
                'label' => $pid ? ($programs[$pid] ?? 'Program #'.$pid) : 'Kayda bağlı olmayan', 'students' => $r['students'],
                'hours' => Dec::round(Dec::div((string) $min, '60'), 1), 'revenue' => $revenue, 'teacher_cost' => $teacher, 'overhead' => $overhead,
                'profit' => $profit, 'margin' => self::pct($profit, $revenue),
            ];
        }
        usort($rows, fn ($a, $b) => bccomp($b['revenue'], $a['revenue'], 2));
        // Yuvarlama artığı / dağıtılamayan tutar ayrı satırda: toplam, gider toplamıyla birebir tutar
        $allocT = array_reduce($rows, fn ($s, $r) => bcadd($s, $r['teacher_cost'], 2), '0.00');
        $allocO = array_reduce($rows, fn ($s, $r) => bcadd($s, $r['overhead'], 2), '0.00');
        $restT = bcsub($salary, $allocT, 2);
        $restO = bcsub($other, $allocO, 2);
        if (! Dec::isZero($restT) || ! Dec::isZero($restO)) {
            $rows[] = ['label' => 'Dağıtılamayan / yuvarlama farkı', 'students' => null, 'hours' => null, 'revenue' => '0.00', 'teacher_cost' => $restT, 'overhead' => $restO,
                'profit' => bcmul(bcadd($restT, $restO, 2), '-1', 2), 'margin' => null];
        }
        $totals = self::totalsOf($rows, ['revenue', 'teacher_cost', 'overhead', 'profit']) + ['students' => null, 'hours' => Dec::round(Dec::div((string) $totalMinutes, '60'), 1)];
        $totals['margin'] = self::pct($totals['profit'], $totals['revenue']);

        return [
            'description' => 'Program başına gelir, öğretmen gideri ve genel gider payı. Basit dağıtım anahtarları kullanılır (aşağıda).',
            'notes' => [
                'Gelir = dönemde programın kayıtlarına alınan tahsilat − iadeler (nakit esaslı).',
                'Öğretmen gideri = dönemdeki "Maaş" kategorisi giderleri × (programın verilen ders saati ÷ toplam ders saati). Ders saati iptal edilmeyen ders oturumlarından hesaplanır.',
                'Genel gider = maaş dışındaki tüm giderler × (programın geliri ÷ toplam gelir).',
                'Bu bir yönetim raporudur; gerçek öğretmen ücretleri program bazında tutulmadığı için tahminidir.',
            ],
            'kpis' => [
                ['label' => 'Toplam gelir', 'value' => $totals['revenue'], 'type' => 'money'],
                ['label' => 'Öğretmen gideri', 'value' => $salary, 'type' => 'money'],
                ['label' => 'Genel gider', 'value' => $other, 'type' => 'money'],
                ['label' => 'Toplam kâr', 'value' => $totals['profit'], 'type' => 'money', 'tone' => bccomp($totals['profit'], '0', 2) < 0 ? 'danger' : 'success'],
            ],
            'chart' => ['type' => 'bar', 'x' => 'label', 'series' => [['key' => 'profit', 'label' => 'Kâr']]],
            'tables' => [
                ['title' => 'Program kârlılığı', 'columns' => [self::col('label', 'Program', 'text'), self::col('students', 'Ödeyen öğrenci', 'number'), self::col('hours', 'Ders saati', 'number'),
                    self::col('revenue', 'Gelir'), self::col('teacher_cost', 'Öğretmen gideri'), self::col('overhead', 'Genel gider'), self::col('profit', 'Kâr'), self::col('margin', 'Marj', 'percent')],
                    'rows' => $rows, 'totals' => $totals, 'chart' => true],
            ],
        ];
    }

    // ------------------------------------------------------------------ 7. mizan

    private function trialBalance(int $b, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tb = $this->ledger->trialBalance($b, $from, $to);
        $rows = array_map(fn ($r) => ['label' => $r['code'].' '.$r['name']] + $r, $tb['rows']);

        return [
            'description' => 'Yevmiye fişlerinden hesap bazında devir, dönem borç/alacak ve kapanış bakiyeleri.',
            'notes' => [
                $tb['balanced'] ? 'Mizan dengede: toplam borç = toplam alacak.' : 'UYARI: Mizan dengede değil — sistem yöneticisine bildirin.',
                'Hesap planı ve eşlemeler Finans > Muhasebe ekranından yönetilir; kodlar muhasebeciyle teyit edilmelidir.',
            ],
            'kpis' => [
                ['label' => 'Dönem borç', 'value' => $tb['totals']['period_debit'], 'type' => 'money'],
                ['label' => 'Dönem alacak', 'value' => $tb['totals']['period_credit'], 'type' => 'money'],
                ['label' => 'Denge', 'value' => $tb['balanced'] ? 'Dengede' : 'Dengesiz', 'type' => 'text', 'tone' => $tb['balanced'] ? 'success' : 'danger'],
            ],
            'balanced' => $tb['balanced'],
            'tables' => [
                ['title' => 'Mizan', 'columns' => [self::col('label', 'Hesap', 'text'), self::col('opening_debit', 'Devir borç'), self::col('opening_credit', 'Devir alacak'),
                    self::col('period_debit', 'Dönem borç'), self::col('period_credit', 'Dönem alacak'), self::col('closing_debit', 'Borç bakiye'), self::col('closing_credit', 'Alacak bakiye')],
                    'rows' => $rows, 'totals' => ['label' => 'Toplam'] + $tb['totals']],
            ],
        ];
    }
}
