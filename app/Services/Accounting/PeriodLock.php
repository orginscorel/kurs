<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\AccountingPeriod;
use App\Services\Finance\FinanceAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Dönem kilidi: kapanmış aya (YYYY-MM) hiçbir yevmiye fişi yazılamaz. Tahsilat, iade, gelir/gider,
 * transfer ve fatura işlemleri fiş ürettiği için aynı kurala otomatik uyar (işlem bütünüyle geri alınır).
 */
class PeriodLock
{
    private static array $cache = [];

    public static function forgetCache(): void
    {
        self::$cache = [];
    }

    public function isClosed(int $branchId, \DateTimeInterface|string $date): bool
    {
        $period = CarbonImmutable::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date)->format('Y-m');
        $key = "{$branchId}:{$period}";

        return self::$cache[$key] ??= DB::table('accounting_periods')->where('branch_id', $branchId)->where('period', $period)->where('status', 'closed')->exists();
    }

    public function assertOpen(int $branchId, \DateTimeInterface|string $date): void
    {
        if ($this->isClosed($branchId, $date)) {
            $d = CarbonImmutable::parse($date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date);
            throw new BusinessRuleException(
                sprintf('%s dönemi muhasebe için kapatılmış; bu tarihe kayıt atılamaz. Tarihi değiştirin ya da yetkili kişiden dönemi açmasını isteyin.', $d->locale('tr')->translatedFormat('F Y')),
                'period_closed',
                ['period' => $d->format('Y-m')],
            );
        }
    }

    public function close(int $branchId, string $period, ?string $note = null): AccountingPeriod
    {
        $p = $this->parse($period);
        if ($p->endOfMonth()->gte(CarbonImmutable::today())) {
            throw new BusinessRuleException('Yalnız bitmiş aylar kapatılabilir.', 'period_not_ended');
        }

        return DB::transaction(function () use ($branchId, $p, $note) {
            $row = AccountingPeriod::query()->where('branch_id', $branchId)->where('period', $p->format('Y-m'))->lockForUpdate()->first();
            if ($row && $row->status === 'closed') {
                throw new BusinessRuleException('Bu dönem zaten kapalı.', 'already_closed');
            }
            $row ??= new AccountingPeriod(['branch_id' => $branchId, 'period' => $p->format('Y-m')]);
            $row->forceFill(['status' => 'closed', 'closed_at' => now(), 'closed_by' => Auth::id(), 'note' => $note ? mb_substr($note, 0, 300) : $row->note])->save();
            self::forgetCache();
            FinanceAudit::log('accounting.period_closed', sprintf('%s muhasebe dönemini kapattı.', $p->locale('tr')->translatedFormat('F Y')));

            return $row;
        });
    }

    public function reopen(int $branchId, string $period, string $reason): AccountingPeriod
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new BusinessRuleException('Dönemi açma gerekçesi en az 5 karakter olmalı.', 'reason_required');
        }
        $p = $this->parse($period);

        return DB::transaction(function () use ($branchId, $p, $reason) {
            $row = AccountingPeriod::query()->where('branch_id', $branchId)->where('period', $p->format('Y-m'))->lockForUpdate()->first();
            if (! $row || $row->status !== 'closed') {
                throw new BusinessRuleException('Bu dönem zaten açık.', 'not_closed');
            }
            $row->forceFill(['status' => 'open', 'reopened_at' => now(), 'reopened_by' => Auth::id(), 'note' => mb_substr('Yeniden açıldı: '.trim($reason), 0, 300)])->save();
            self::forgetCache();
            FinanceAudit::log('accounting.period_reopened', sprintf('%s muhasebe dönemini yeniden açtı. Gerekçe: %s', $p->locale('tr')->translatedFormat('F Y'), $reason));

            return $row;
        });
    }

    private function parse(string $period): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new BusinessRuleException('Dönem YYYY-AA biçiminde olmalı.', 'invalid_period');
        }

        return CarbonImmutable::parse($period.'-01');
    }
}
