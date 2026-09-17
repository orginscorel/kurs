<?php

namespace App\Services\Discipline;

use App\Exceptions\BusinessRuleException;
use App\Support\Discipline\DisciplineCatalog as C;
use Carbon\CarbonImmutable;

/**
 * Disiplin sürecinin saf kuralları (veritabanı kullanmaz; birim testleriyle korunur):
 * durum geçişleri, yetki (kim tek başına verir / kurul), uzaklaştırma tarihleri ve çakışma, düşme tarihi, uyarı seviyesi.
 */
final class DisciplineRules
{
    public static function canMoveIncident(string $from, string $to): bool
    {
        return in_array($to, C::INCIDENT_TRANSITIONS[$from] ?? [], true);
    }

    public static function assertIncidentMove(string $from, string $to): void
    {
        if (! self::canMoveIncident($from, $to)) {
            throw new BusinessRuleException(sprintf('Olay "%s" durumundan "%s" durumuna alınamaz.', C::INCIDENT_STATUSES[$from] ?? $from, C::INCIDENT_STATUSES[$to] ?? $to), 'discipline_invalid_transition', ['from' => $from, 'to' => $to], 422);
        }
    }

    public static function canMoveSanction(string $from, string $to): bool
    {
        return in_array($to, C::SANCTION_TRANSITIONS[$from] ?? [], true);
    }

    public static function assertSanctionMove(string $from, string $to): void
    {
        if (! self::canMoveSanction($from, $to)) {
            throw new BusinessRuleException(sprintf('Yaptırım "%s" durumundan "%s" durumuna alınamaz.', C::SANCTION_STATUSES[$from] ?? $from, C::SANCTION_STATUSES[$to] ?? $to), 'discipline_invalid_sanction_transition', ['from' => $from, 'to' => $to], 422);
        }
    }

    /**
     * Yeni yaptırımın ilk durumu. Kurul yetkisindeki kademe her zaman "öneri" olarak başlar ve yalnız kurul
     * kararıyla yürürlüğe girer; diğerleri discipline.decide yetkilisi tarafından doğrudan verilir.
     *
     * @param  callable(string): bool  $can  yetki denetleyici
     */
    public static function initialSanctionStatus(string $authority, callable $can): string
    {
        if ($authority === 'board') {
            if (! $can('discipline.decide') && ! $can('discipline.board')) {
                throw new BusinessRuleException('Yaptırım önermek için "disiplin kararı verme" yetkisi gerekir.', 'discipline_forbidden', [], 403);
            }

            return 'proposed';
        }
        if (! $can('discipline.decide')) {
            throw new BusinessRuleException('Bu yaptırımı vermek için "disiplin kararı verme" yetkiniz yok.', 'discipline_forbidden', [], 403);
        }

        return 'active';
    }

    /** İtirazı kim karara bağlar: kurul kademesi → discipline.board, diğerleri → discipline.decide. */
    public static function appealPermission(string $authority): string
    {
        return $authority === 'board' ? 'discipline.board' : 'discipline.decide';
    }

    /** Kurul yetkisindeki yaptırım yürürlüğe girmeden önce savunma alınmış (ya da alınmadığı kayda geçmiş) olmalı. */
    public static function assertDefenseSettled(?string $defenseStatus): void
    {
        if ($defenseStatus === null) {
            throw new BusinessRuleException('Kurul kararından önce öğrencinin savunması istenmeli (ya da "savunma alınmadı" olarak işaretlenmeli).', 'discipline_defense_missing', [], 422);
        }
        if ($defenseStatus === 'requested') {
            throw new BusinessRuleException('Öğrencinin savunması henüz alınmadı. Savunmayı kaydedin ya da "savunma alınmadı" olarak işaretleyin.', 'discipline_defense_pending', [], 422);
        }
    }

    /**
     * Uzaklaştırma tarih aralığı: başlangıç + gün sayısı (takvim günü, başlangıç dahil).
     *
     * @return array{0: string, 1: string} [başlangıç, bitiş] Y-m-d
     */
    public static function suspensionRange(string $startsOn, int $days): array
    {
        if ($days < 1 || $days > 30) {
            throw new BusinessRuleException('Uzaklaştırma 1 ile 30 gün arasında olmalı.', 'discipline_suspension_days', ['days' => $days], 422);
        }
        $start = CarbonImmutable::parse($startsOn)->startOfDay();

        return [$start->toDateString(), $start->addDays($days - 1)->toDateString()];
    }

    /** İki kapalı tarih aralığı çakışıyor mu (uçlar dahil). */
    public static function rangesOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    /**
     * @param  iterable<array{starts_on:string, ends_on:string, sanction_no?:string}>  $existing
     */
    public static function assertNoSuspensionOverlap(string $start, string $end, iterable $existing): void
    {
        foreach ($existing as $e) {
            if (self::rangesOverlap($start, $end, $e['starts_on'], $e['ends_on'])) {
                throw new BusinessRuleException(sprintf('Öğrencinin %s – %s tarihleri arasında başka bir uzaklaştırması var%s.',
                    CarbonImmutable::parse($e['starts_on'])->format('d.m.Y'), CarbonImmutable::parse($e['ends_on'])->format('d.m.Y'),
                    ! empty($e['sanction_no']) ? ' ('.$e['sanction_no'].')' : ''), 'discipline_suspension_overlap', [], 422);
            }
        }
    }

    /** Yaptırımın düşeceği tarih: bitiş (yoksa karar) tarihi + kademe süresi; süresizse null. */
    public static function expiresOn(?int $expiresAfterDays, string $decidedOn, ?string $endsOn = null): ?string
    {
        if (! $expiresAfterDays) {
            return null;
        }
        $base = CarbonImmutable::parse($endsOn && $endsOn > $decidedOn ? $endsOn : $decidedOn);

        return $base->addDays($expiresAfterDays)->toDateString();
    }

    /** Dönem net puanı: ceza − (ayar açıksa) olumlu puan; eksiye düşmez. */
    public static function netPoints(int $penalty, int $merit, bool $meritOffsets): int
    {
        return max(0, $penalty - ($meritOffsets ? $merit : 0));
    }

    /** @param array<string, mixed> $settings */
    public static function level(int $net, array $settings): string
    {
        $s = $settings + C::SETTINGS;

        return match (true) {
            $net >= (int) $s['threshold_critical'] => 'critical',
            $net >= (int) $s['threshold_warning'] => 'warning',
            $net >= (int) $s['threshold_watch'] => 'watch',
            default => 'none',
        };
    }

    /** Risk puanına katkı (en fazla 10): kritik eşikte tam puan. */
    public static function riskPoints(int $net, array $settings): float
    {
        $critical = max(1, (int) (($settings + C::SETTINGS)['threshold_critical']));

        return round(min(10, $net / $critical * 10), 1);
    }

    /** Olayın ciddiyeti: seçilen davranışların en ağırı (hiç yoksa hafif). @param iterable<string> $severities */
    public static function maxSeverity(iterable $severities): string
    {
        $best = 'low';
        foreach ($severities as $s) {
            if ((C::SEVERITY_RANK[$s] ?? 0) > C::SEVERITY_RANK[$best]) {
                $best = $s;
            }
        }

        return $best;
    }

    /** Kurul maddesinde kabul için oy çokluğu gerekir; oylar hazır bulunan üye sayısını aşamaz. */
    public static function assertVotes(string $result, int $for, int $against, int $abstain, int $present): void
    {
        if ($present > 0 && $for + $against + $abstain > $present) {
            throw new BusinessRuleException("Toplam oy ({$for}+{$against}+{$abstain}) toplantıda bulunan üye sayısını ({$present}) aşamaz.", 'discipline_votes_exceed', [], 422);
        }
        if ($result === 'accepted' && $for <= $against) {
            throw new BusinessRuleException('Kabul için kabul oyu ret oyundan fazla olmalı.', 'discipline_votes_majority', [], 422);
        }
        if ($result === 'rejected' && $for > $against) {
            throw new BusinessRuleException('Kabul oyu çoğunlukta; madde reddedilemez.', 'discipline_votes_majority', [], 422);
        }
    }
}
