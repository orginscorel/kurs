<?php

namespace App\Services\Academic;

use App\Exceptions\BusinessRuleException;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\TimeTemplate;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Zaman şablonları: doğrulama, hızlı oluşturucu, sınıflara atama (tek tek ya da seviyeye göre). */
class TimeTemplateService
{
    public const MAX_PER_DAY = 14;

    /**
     * Hızlı oluşturucu (saf): seçili günler için başlangıç + ders süresi + teneffüs + adet (+ öğle arası).
     *
     * @param  array{weekdays:list<int>, start:string, lesson_minutes:int, break_minutes:int, count:int, lunch_after?:?int, lunch_minutes?:?int}  $g
     * @return array<string, list<array{0:string,1:string}>>
     */
    public static function generate(array $g): array
    {
        $len = max(20, min(120, (int) $g['lesson_minutes']));
        $break = max(0, min(60, (int) $g['break_minutes']));
        $count = max(1, min(self::MAX_PER_DAY, (int) $g['count']));
        $lunchAfter = (int) ($g['lunch_after'] ?? 0);
        $lunch = max(0, min(120, (int) ($g['lunch_minutes'] ?? 0)));

        $periods = [];
        $t = TimeSlots::toMinutes($g['start']);
        for ($k = 1; $k <= $count; $k++) {
            if ($t + $len > 24 * 60) {
                break;
            }
            $periods[] = [TimeSlots::toTime($t), TimeSlots::toTime($t + $len)];
            $t += $len + ($lunchAfter > 0 && $k === $lunchAfter && $lunch > 0 ? $lunch : $break);
        }

        $days = [];
        foreach (array_unique(array_map('intval', $g['weekdays'])) as $wd) {
            if ($wd >= 1 && $wd <= 7) {
                $days[(string) $wd] = $periods;
            }
        }
        ksort($days);

        return $days;
    }

    /**
     * Gün → dilim listesini doğrular ve normalleştirir (sıralı, çakışmasız, 'HH:MM').
     *
     * @return array<string, list<array{0:string,1:string}>>
     */
    public static function normalizeDays(array $days): array
    {
        $out = [];
        foreach ($days as $wd => $slots) {
            $wd = (int) $wd;
            if ($wd < 1 || $wd > 7 || ! is_array($slots) || $slots === []) {
                continue;
            }
            $list = [];
            foreach ($slots as $slot) {
                $s = $slot[0] ?? $slot['start'] ?? null;
                $e = $slot[1] ?? $slot['end'] ?? null;
                if (! is_string($s) || ! is_string($e) || ! preg_match('/^\d{1,2}:\d{2}$/', $s) || ! preg_match('/^\d{1,2}:\d{2}$/', $e)) {
                    throw new BusinessRuleException(TimeSlots::WEEKDAYS[$wd].' için geçersiz saat biçimi (SS:DD).', 'invalid_time');
                }
                $sm = TimeSlots::toMinutes($s);
                $em = TimeSlots::toMinutes($e);
                if ($em - $sm < 20) {
                    throw new BusinessRuleException(TimeSlots::WEEKDAYS[$wd]." $s–$e: ders dilimi en az 20 dakika olmalı.", 'slot_too_short');
                }
                $list[] = [$sm, $em];
            }
            usort($list, fn ($a, $b) => $a[0] <=> $b[0]);
            for ($k = 1; $k < count($list); $k++) {
                if ($list[$k][0] < $list[$k - 1][1]) {
                    throw new BusinessRuleException(sprintf('%s günü %s–%s ile %s–%s dilimleri çakışıyor.', TimeSlots::WEEKDAYS[$wd],
                        TimeSlots::toTime($list[$k - 1][0]), TimeSlots::toTime($list[$k - 1][1]), TimeSlots::toTime($list[$k][0]), TimeSlots::toTime($list[$k][1])), 'slot_overlap');
                }
            }
            if (count($list) > self::MAX_PER_DAY) {
                throw new BusinessRuleException(TimeSlots::WEEKDAYS[$wd].' için en fazla '.self::MAX_PER_DAY.' ders dilimi tanımlanabilir.', 'too_many_slots');
            }
            $out[(string) $wd] = array_map(fn ($x) => [TimeSlots::toTime($x[0]), TimeSlots::toTime($x[1])], $list);
        }
        if ($out === []) {
            throw new BusinessRuleException('Şablonda en az bir gün ve ders dilimi olmalı.', 'empty_template');
        }
        ksort($out);

        return $out;
    }

    public function create(array $data): TimeTemplate
    {
        return DB::transaction(function () use ($data) {
            $t = TimeTemplate::query()->create($this->attributes($data));
            if (array_key_exists('class_group_ids', $data)) {
                $t->classGroups()->sync($this->validClassIds($data['class_group_ids']));
            }
            Audit::log('time_template.created', "{$t->name} zaman şablonunu oluşturdu (".$this->summary($t).').');

            return $t;
        });
    }

    public function update(TimeTemplate $t, array $data): TimeTemplate
    {
        return DB::transaction(function () use ($t, $data) {
            $t->fill($this->attributes($data + $t->only(['name', 'description', 'days', 'generator', 'levels', 'is_active'])))->save();
            if (array_key_exists('class_group_ids', $data)) {
                $t->classGroups()->sync($this->validClassIds($data['class_group_ids']));
            }
            Audit::log('time_template.updated', "{$t->name} zaman şablonunu güncelledi (".$this->summary($t).').');

            return $t;
        });
    }

    public function delete(TimeTemplate $t): void
    {
        DB::transaction(function () use ($t) {
            $n = $t->classGroups()->count();
            $t->classGroups()->detach();
            $t->delete();
            Audit::log('time_template.deleted', "{$t->name} zaman şablonunu sildi ($n sınıftan kaldırıldı; mevcut ders programı değişmedi).");
        });
    }

    /** Şablonu olmayan aktif sınıflara, seviyesi şablonun seviyelerinde olan şablonları bağlar. */
    public function autoAssign(bool $onlyEmpty = true): array
    {
        $templates = TimeTemplate::query()->where('is_active', true)->get()->filter(fn ($t) => ! empty($t->levels));
        $termIds = AcademicTerm::query()->where('is_current', true)->pluck('id');
        $groups = ClassGroup::query()->withCount('timeTemplates')->where('is_active', true)
            ->when($termIds->isNotEmpty(), fn ($q) => $q->whereIn('academic_term_id', $termIds))->get();

        $assigned = [];
        DB::transaction(function () use ($templates, $groups, $onlyEmpty, &$assigned) {
            foreach ($groups as $g) {
                if ($onlyEmpty && $g->time_templates_count > 0) {
                    continue;
                }
                $level = TimeTemplate::levelOf($g->name);
                if ($level === null) {
                    continue;
                }
                $ids = $templates->filter(fn ($t) => in_array($level, array_map('intval', $t->levels), true))->pluck('id')->all();
                if ($ids) {
                    $g->timeTemplates()->syncWithoutDetaching($ids);
                    $assigned[] = $g->name;
                }
            }
            if ($assigned) {
                Audit::log('time_template.auto_assigned', count($assigned).' sınıfa seviyesine göre zaman şablonu atadı: '.implode(', ', $assigned).'.');
            }
        });

        return $assigned;
    }

    private function attributes(array $data): array
    {
        $days = ! empty($data['generator']) && empty($data['days']) ? self::generate($data['generator']) : self::normalizeDays($data['days'] ?? []);
        $levels = array_values(array_unique(array_filter(array_map('intval', (array) ($data['levels'] ?? [])), fn ($l) => $l >= 1 && $l <= 12)));
        sort($levels);

        return [
            'name' => trim((string) $data['name']), 'description' => $data['description'] ?? null, 'days' => $days,
            'generator' => $data['generator'] ?? null, 'levels' => $levels ?: null, 'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function validClassIds(?array $ids): array
    {
        return ClassGroup::query()->whereIn('id', array_map('intval', $ids ?? []))->pluck('id')->all();
    }

    private function summary(TimeTemplate $t): string
    {
        $days = collect($t->days)->keys()->map(fn ($wd) => mb_substr(TimeSlots::WEEKDAYS[(int) $wd], 0, 3))->implode(', ');
        $count = collect($t->days)->sum(fn ($slots) => count($slots));

        return "$days · $count ders saati";
    }
}
