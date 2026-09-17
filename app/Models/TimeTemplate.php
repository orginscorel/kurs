<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Zaman şablonu: hangi günlerde hangi ders saati dilimleri var.
 * days = {"1": [["16:30","17:10"], ["17:20","18:00"]], "6": [...]} (ISO gün → dilimler)
 */
class TimeTemplate extends Model
{
    use BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'description', 'days', 'generator', 'levels', 'is_active'];

    protected $casts = ['days' => 'array', 'generator' => 'array', 'levels' => 'array', 'is_active' => 'boolean'];

    /** Sınıf adından seviye: "12-A" → 12, "9-B" → 9, "MEZUN-EA" → null */
    public static function levelOf(string $className): ?int
    {
        return preg_match('/^\s*(\d{1,2})\b/u', $className, $m) ? (int) $m[1] : null;
    }

    public function classGroups(): BelongsToMany
    {
        return $this->belongsToMany(ClassGroup::class, 'class_group_time_template');
    }

    /** @return list<array{weekday:int,start:string,end:string}> */
    public function periods(): array
    {
        $out = [];
        foreach ((array) $this->days as $weekday => $slots) {
            foreach ((array) $slots as $slot) {
                $out[] = ['weekday' => (int) $weekday, 'start' => (string) $slot[0], 'end' => (string) $slot[1]];
            }
        }
        usort($out, fn ($a, $b) => [$a['weekday'], $a['start']] <=> [$b['weekday'], $b['start']]);

        return $out;
    }
}
