<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\Holiday;
use App\Models\LessonSession;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tatil yönetimi. Tatil eklenince aralıktaki (yoklaması alınmamış) oturumlar gerekçeyle iptal edilir ve
 * her etkilenen öğrenciye tek toplu bildirim atılır. Tatil silinir/değişirse gelecekteki iptaller geri alınır.
 * İleride üretilecek oturumları SessionGenerator tatile bakarak iptal durumunda üretir.
 */
class HolidayService
{
    public function create(array $data, ?int $userId): Holiday
    {
        $data = $this->prepare($data);

        return DB::transaction(function () use ($data, $userId) {
            $h = Holiday::query()->create($data + ['created_by' => $userId]);
            $count = $h->cancel_sessions ? $this->apply($h) : 0;
            Audit::log('holiday.created', sprintf('%s tatilini ekledi (%s–%s); %d ders iptal edildi.', $h->name, $h->starts_on->format('d.m.Y'), $h->ends_on->format('d.m.Y'), $count));

            return $h;
        });
    }

    public function update(Holiday $h, array $data): Holiday
    {
        $data = $this->prepare(array_merge($h->only(['name', 'kind', 'notes']), [
            'starts_on' => $h->starts_on->toDateString(), 'ends_on' => $h->ends_on->toDateString(), 'cancel_sessions' => $h->cancel_sessions,
        ], $data));

        return DB::transaction(function () use ($h, $data) {
            $released = $this->release($h);
            $h->fill($data)->save();
            $count = $h->cancel_sessions ? $this->apply($h, notify: true) : 0;
            if (! $h->cancel_sessions) {
                $h->forceFill(['cancelled_count' => LessonSession::query()->where('holiday_id', $h->id)->where('status', 'cancelled')->count()])->save();
            }
            Audit::log('holiday.updated', sprintf('%s tatilini güncelledi (%s–%s); %d iptal geri alındı, %d ders iptal edildi.', $h->name, $h->starts_on->format('d.m.Y'), $h->ends_on->format('d.m.Y'), $released, $count));

            return $h;
        });
    }

    public function delete(Holiday $h): int
    {
        return DB::transaction(function () use ($h) {
            $released = $this->release($h);
            $h->delete();
            Audit::log('holiday.deleted', sprintf('%s tatilini sildi; gelecekteki %d dersin iptali geri alındı.', $h->name, $released));

            return $released;
        });
    }

    /** Aralıktaki dokunulmamış oturumları iptal eder; etkilenen sınıflara toplu bildirim. */
    private function apply(Holiday $h, bool $notify = true): int
    {
        $query = LessonSession::query()->whereBetween('date', [$h->starts_on->toDateString(), $h->ends_on->toDateString()])
            ->where('status', 'scheduled')->whereNull('attendance_taken_at')->whereDoesntHave('attendances')->whereNull('holiday_id');
        $groupIds = (clone $query)->where('starts_at', '>', now())->distinct()->pluck('class_group_id')->all();
        $count = $query->update(['status' => 'cancelled', 'cancel_reason' => HolidayCalendar::reason(['name' => $h->name]), 'holiday_id' => $h->id, 'updated_at' => now()]);
        // Sayaç: bu tatile bağlı tüm iptaller (düzenlemede geri alınmayan geçmiş iptaller dahil)
        $h->forceFill(['cancelled_count' => LessonSession::query()->where('holiday_id', $h->id)->where('status', 'cancelled')->count()])->save();

        if ($notify && $groupIds) {
            LessonNotifier::holiday($h, $groupIds);
        }

        return $count;
    }

    /** Gelecekteki tatil iptallerini geri alır (geçmiş iptaller kayıt olarak kalır). */
    private function release(Holiday $h): int
    {
        return LessonSession::query()->where('holiday_id', $h->id)->where('date', '>=', CarbonImmutable::today()->toDateString())
            ->update(['status' => 'scheduled', 'cancel_reason' => null, 'holiday_id' => null, 'updated_at' => now()]);
    }

    private function prepare(array $data): array
    {
        $from = CarbonImmutable::parse($data['starts_on']);
        $to = CarbonImmutable::parse($data['ends_on'] ?? $data['starts_on']);
        if ($to->lt($from)) {
            throw new BusinessRuleException('Tatil bitişi başlangıçtan önce olamaz.', 'invalid_range');
        }
        if ($from->diffInDays($to) > 120) {
            throw new BusinessRuleException('Tatil en fazla 120 gün olabilir.', 'range_too_wide');
        }

        return [
            'name' => trim($data['name']), 'kind' => $data['kind'] ?? 'official', 'notes' => $data['notes'] ?? null,
            'starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString(), 'cancel_sessions' => (bool) ($data['cancel_sessions'] ?? true),
        ];
    }
}
