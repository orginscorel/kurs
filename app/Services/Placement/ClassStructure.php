<?php

namespace App\Services\Placement;

use App\Exceptions\BusinessRuleException;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\Program;
use App\Models\Teacher;
use App\Models\TimeTemplate;
use App\Support\Audit;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kurum sınıf yapısı — ayarlardan (`classes.structure`) okunan, düzenlenebilir yapı.
 *
 * Seviye (9, 10, 11, 12, Mezun=13 …) başına: şubesiz tek sınıf ("11") ya da şubeli ("11-A", "11-B").
 * Her şube: alan (SAY/EA/SÖZ/DİL/TYT/LGS), kapasite, program, zaman şablonları, ana derslik,
 * rehber öğretmen, kısa ad, renk. Ayar kaydedilmemişse yapı BOŞTUR (seviye yok): kurumun LGS/TYT/Mezun
 * düzenine uymayan 9-12 A/B varsayımı yapılmaz; ekranlar isConfigured() ile "Sınıf yapınızı tanımlayın" gösterir.
 * (Eski kurulumlarda açıkça kaydedilmiş classes.levels/classes.sections varsa onlardan yapı üretilir.)
 *
 * İç anahtar: "{seviye}-{şube}" (şubesiz sınıfın şube anahtarı "-"). Görünen ad: className().
 */
class ClassStructure
{
    public const DEFAULT_CAPACITY = 15;
    public const DEFAULT_SECTIONS = ['A', 'B'];
    public const DEFAULT_LEVELS = [9, 10, 11, 12];
    public const NO_SECTION = '-';
    public const GRADUATE = 13;
    public const MAX_SECTIONS = 12;
    public const NAME_PATTERN = '/^(\d{1,2}|mezun)(?:-([A-Z]))?$/iu';

    /** Alan kodları öğrenci kartındaki `field` ile aynı. */
    public const TRACKS = ['SAY' => 'Sayısal', 'EA' => 'Eşit ağırlık', 'SOZ' => 'Sözel', 'DIL' => 'Dil', 'TYT' => 'TYT (temel)', 'LGS' => 'LGS'];

    /** Şube harfi → alan varsayılanı (ayardan değiştirilebilir). */
    public const DEFAULT_TRACK_BY_SECTION = ['A' => 'SAY', 'B' => 'EA', 'C' => 'SOZ', 'D' => 'DIL'];

    public const COLORS = ['indigo', 'blue', 'teal', 'green', 'amber', 'orange', 'rose', 'violet', 'slate'];

    /** Seviye → program kodu (eksikse oluşturulur). */
    public const LEVEL_PROGRAMS = [
        9 => ['ARA', 'Ara Sınıf (9-10)', 'TYT'],
        10 => ['ARA', 'Ara Sınıf (9-10)', 'TYT'],
        11 => ['TYT', 'TYT Hazırlık', 'TYT'],
        12 => ['YKS', 'YKS Hazırlık', 'TYT'],
        13 => ['MEZUN', 'YKS Mezun', 'TYT'],
    ];

    /** Alan → tercih edilen program kodu (varsa kullanılır, yoksa seviye programı). */
    public const TRACK_PROGRAMS = ['SAY' => 'AYT_SAY', 'EA' => 'AYT_EA', 'SOZ' => 'AYT_SOZ', 'LGS' => 'LGS'];

    private ?array $cache = null;

    // ================================================================== ayar okuma

    /** @return array{capacity:int, sections:list<string>, levels:list<int>, siblings_apart:bool} */
    public function settings(): array
    {
        $structure = $this->structure();
        $sections = [];
        foreach ($structure['levels'] as $l) {
            foreach ($l['sections'] as $s) {
                $sections[$s['code']] = true;
            }
        }

        return [
            'capacity' => $structure['default_capacity'],
            'sections' => array_map('strval', array_keys($sections)),
            'levels' => array_column($structure['levels'], 'grade'),
            'siblings_apart' => (bool) Settings::get('classes.siblings_apart', true),
        ];
    }

    /**
     * Normalleştirilmiş yapı.
     *
     * @return array{default_capacity:int, track_defaults:array<string,string>, levels:list<array{grade:int, label:string, sectioned:bool, sections:list<array>}>}
     */
    public function structure(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = Settings::get('classes.structure');
        $capacity = max(1, (int) Settings::get('classes.capacity', self::DEFAULT_CAPACITY));

        if (! is_array($stored) || empty($stored['levels'])) {
            $levels = Settings::get('classes.levels');
            $sections = Settings::get('classes.sections');
            if (! is_array($levels) || $levels === []) {
                // Yapı tanımlanmamış: varsayım yok, boş yapı.
                return $this->cache = self::emptyStructure($capacity);
            }
            $stored = self::defaultStructure(
                array_values(array_map('intval', $levels)),
                array_values(array_map('strval', is_array($sections) && $sections ? $sections : self::DEFAULT_SECTIONS)),
                $capacity,
            );
        }

        return $this->cache = self::normalize($stored, $capacity);
    }

    /** Kurumun en az bir seviyesi tanımlı mı? (false → arayüz "Sınıf yapınızı tanımlayın" boş durumu) */
    public function isConfigured(): bool
    {
        return $this->structure()['levels'] !== [];
    }

    /** Tanımsız yapı: seviye yok, yalnız varsayılan kapasite ve alan önerileri. */
    public static function emptyStructure(int $capacity = self::DEFAULT_CAPACITY): array
    {
        $trackDefaults = self::DEFAULT_TRACK_BY_SECTION;
        ksort($trackDefaults);

        return ['default_capacity' => max(1, min(60, $capacity)), 'track_defaults' => $trackDefaults, 'levels' => []];
    }

    public function isCustomized(): bool
    {
        $stored = Settings::get('classes.structure');

        return is_array($stored) && ! empty($stored['levels']);
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /** @return list<string> seviyenin şube anahtarları ("A","B" ya da "-") */
    public function sectionsFor(int $level): array
    {
        foreach ($this->structure()['levels'] as $l) {
            if ($l['grade'] === $level) {
                return array_column($l['sections'], 'code');
            }
        }

        return [];
    }

    /** @return array|null şube tanımı */
    public function spec(int $level, string $section): ?array
    {
        foreach ($this->structure()['levels'] as $l) {
            if ($l['grade'] === $level) {
                foreach ($l['sections'] as $s) {
                    if ($s['code'] === $section) {
                        return $s;
                    }
                }
            }
        }

        return null;
    }

    /** Eski sabit varsayımın yapı karşılığı (ayar kaydedilmemişse). */
    public static function defaultStructure(array $levels, array $sections, int $capacity = self::DEFAULT_CAPACITY): array
    {
        return [
            'default_capacity' => $capacity,
            'track_defaults' => self::DEFAULT_TRACK_BY_SECTION,
            'levels' => array_map(fn (int $grade) => [
                'grade' => $grade,
                'sectioned' => true,
                'sections' => array_map(fn (string $code) => [
                    'code' => $code,
                    'track' => self::defaultTrack($grade, $code, self::DEFAULT_TRACK_BY_SECTION),
                    'capacity' => $capacity,
                ], $sections),
            ], $levels),
        ];
    }

    /** Seviye + şube için alan önerisi: ≤8 LGS, 9-11 TYT, 12/Mezun şube harfine göre. */
    public static function defaultTrack(int $grade, string $code, array $trackDefaults = self::DEFAULT_TRACK_BY_SECTION): ?string
    {
        if ($grade <= 8) {
            return 'LGS';
        }
        if ($grade <= 11) {
            return 'TYT';
        }

        return $trackDefaults[$code] ?? null;
    }

    /**
     * Girdi yapıyı doğrular ve normalleştirir (API'den gelen ya da kayıtlı).
     *
     * @throws BusinessRuleException geçersiz yapı
     */
    public static function normalize(array $input, int $fallbackCapacity = self::DEFAULT_CAPACITY): array
    {
        $capacity = max(1, min(60, (int) ($input['default_capacity'] ?? $fallbackCapacity)));
        $trackDefaults = [];
        foreach ((array) ($input['track_defaults'] ?? self::DEFAULT_TRACK_BY_SECTION) as $letter => $track) {
            $letter = mb_strtoupper((string) $letter);
            if (preg_match('/^[A-Z]$/', $letter) && is_string($track) && isset(self::TRACKS[$track])) {
                $trackDefaults[$letter] = $track;
            }
        }
        ksort($trackDefaults);

        $levels = [];
        foreach ((array) ($input['levels'] ?? []) as $l) {
            $grade = (int) ($l['grade'] ?? 0);
            if ($grade < 1 || $grade > self::GRADUATE) {
                throw new BusinessRuleException('Sınıf seviyesi 1 ile 12 arasında ya da "Mezun" olmalı.', 'structure_invalid_level');
            }
            if (isset($levels[$grade])) {
                throw new BusinessRuleException(self::levelLabel($grade).' iki kez tanımlanmış.', 'structure_duplicate_level');
            }
            $sectioned = (bool) ($l['sectioned'] ?? true);
            $rawSections = array_values((array) ($l['sections'] ?? []));
            if (! $sectioned) {
                $first = (array) ($rawSections[0] ?? []);
                $first['code'] = self::NO_SECTION;
                $rawSections = [$first];
            }
            if ($rawSections === []) {
                throw new BusinessRuleException(self::levelLabel($grade).' için en az bir şube ekleyin ya da şubesiz seçin.', 'structure_no_sections');
            }
            if (count($rawSections) > self::MAX_SECTIONS) {
                throw new BusinessRuleException(self::levelLabel($grade).' için en fazla '.self::MAX_SECTIONS.' şube açılabilir.', 'structure_too_many_sections');
            }
            $sections = [];
            foreach ($rawSections as $s) {
                $s = (array) $s;
                $code = $sectioned ? mb_strtoupper(trim((string) ($s['code'] ?? ''))) : self::NO_SECTION;
                if ($sectioned && ! preg_match('/^[A-Z]$/', $code)) {
                    throw new BusinessRuleException(self::levelLabel($grade).': şube adı tek harf olmalı (A-Z).', 'structure_invalid_section');
                }
                if (isset($sections[$code])) {
                    throw new BusinessRuleException(self::levelLabel($grade)." {$code} şubesi iki kez tanımlanmış.", 'structure_duplicate_section');
                }
                $track = isset($s['track']) && is_string($s['track']) && isset(self::TRACKS[$s['track']]) ? $s['track'] : null;
                $color = isset($s['color']) && in_array($s['color'], self::COLORS, true) ? $s['color'] : null;
                $short = isset($s['short_name']) ? mb_substr(trim((string) $s['short_name']), 0, 20) : '';
                $sections[$code] = [
                    'code' => $code,
                    'track' => $track,
                    'capacity' => max(1, min(60, (int) ($s['capacity'] ?? $capacity))),
                    'program_id' => ! empty($s['program_id']) ? (int) $s['program_id'] : null,
                    'time_template_ids' => array_values(array_unique(array_map('intval', array_filter((array) ($s['time_template_ids'] ?? []))))),
                    'homeroom_classroom_id' => ! empty($s['homeroom_classroom_id']) ? (int) $s['homeroom_classroom_id'] : null,
                    'advisor_teacher_id' => ! empty($s['advisor_teacher_id']) ? (int) $s['advisor_teacher_id'] : null,
                    'short_name' => $short !== '' ? $short : null,
                    'color' => $color,
                ];
            }
            ksort($sections, SORT_STRING);
            $levels[$grade] = ['grade' => $grade, 'label' => self::levelLabel($grade), 'sectioned' => $sectioned, 'sections' => array_values($sections)];
        }
        if ($levels === []) {
            throw new BusinessRuleException('En az bir sınıf seviyesi tanımlayın.', 'structure_empty');
        }
        ksort($levels);

        return ['default_capacity' => $capacity, 'track_defaults' => $trackDefaults, 'levels' => array_values($levels)];
    }

    /** Kaydedilecek biçim (etiketler çıkarılır). */
    public static function storable(array $normalized): array
    {
        $normalized['levels'] = array_map(fn ($l) => array_diff_key($l, ['label' => true]), $normalized['levels']);

        return $normalized;
    }

    // ================================================================== adlandırma

    public static function levelLabel(int $grade): string
    {
        return $grade === self::GRADUATE ? 'Mezun' : "{$grade}. sınıf";
    }

    /** "10", "10. sınıf", "10.Sınıf" → 10; "Mezun" → 13; boş → null */
    public static function gradeOf(?string $schoolGrade): ?int
    {
        if ($schoolGrade === null) {
            return null;
        }
        if (preg_match('/^\s*mezun/iu', $schoolGrade)) {
            return self::GRADUATE;
        }
        if (! preg_match('/^\s*(\d{1,2})/', $schoolGrade, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /** Veritabanında seviye eşleşmesi için kullanılacak school_grade değerleri. */
    public static function gradeValues(int $level): array
    {
        if ($level === self::GRADUATE) {
            return ['Mezun', 'mezun', 'MEZUN'];
        }

        return [(string) $level, "{$level}. sınıf", "{$level}. Sınıf", "{$level}.sınıf"];
    }

    /** İç anahtar: "10-A", şubesiz "11--". */
    public static function key(int $level, string $section): string
    {
        return "{$level}-{$section}";
    }

    /** Görünen ad: "10-A", şubesiz "11", mezun "Mezun-A" / "Mezun". */
    public static function className(int $level, ?string $section): string
    {
        $prefix = $level === self::GRADUATE ? 'Mezun' : (string) $level;

        return $section === null || $section === self::NO_SECTION ? $prefix : "{$prefix}-{$section}";
    }

    /** Ada göre seviye/şube çözümü: "10-A" → [10,'A'], "11" → [11,'-'], "Mezun-B" → [13,'B']. */
    public static function parseName(string $name): ?array
    {
        if (! preg_match(self::NAME_PATTERN, trim($name), $m)) {
            return null;
        }
        $level = mb_strtolower($m[1]) === 'mezun' ? self::GRADUATE : (int) $m[1];

        return [$level, isset($m[2]) && $m[2] !== '' ? mb_strtoupper($m[2]) : self::NO_SECTION];
    }

    // ================================================================== sınıflar

    /**
     * Dönemin yapılandırılmış sınıfları: grade_level/section dolu ya da adı kalıba uyan.
     *
     * @return Collection<string, object> iç anahtar ("10-A", "11--") ile
     */
    public function groups(int $termId, bool $activeOnly = true): Collection
    {
        $allowed = [];
        foreach ($this->structure()['levels'] as $l) {
            $allowed[$l['grade']] = array_column($l['sections'], 'code');
        }

        return ClassGroup::query()->where('academic_term_id', $termId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')->get(['id', 'name', 'grade_level', 'section', 'track', 'capacity', 'program_id', 'is_active'])
            ->map(function (ClassGroup $g) {
                [$level, $section] = self::resolve($g->name, $g->grade_level === null ? null : (int) $g->grade_level, $g->section);

                return (object) ['id' => $g->id, 'name' => $g->name, 'grade_level' => $level, 'section' => $section, 'track' => $g->track,
                    'capacity' => (int) $g->capacity, 'program_id' => (int) $g->program_id, 'is_active' => (bool) $g->is_active];
            })
            ->filter(fn ($g) => $g->grade_level !== null && isset($allowed[$g->grade_level]) && in_array($g->section, $allowed[$g->grade_level], true))
            ->sortBy(fn ($g) => sprintf('%02d-%s-%010d', $g->grade_level, $g->section, $g->id))
            ->unique(fn ($g) => self::key($g->grade_level, $g->section))
            ->keyBy(fn ($g) => self::key($g->grade_level, $g->section));
    }

    /**
     * Sınıf kaydından [seviye, şube anahtarı]. Seviye işaretli + şube boş → şubesiz ("-").
     *
     * @return array{0:?int, 1:?string}
     */
    public static function resolve(string $name, ?int $level, ?string $section): array
    {
        $parsed = self::parseName($name);
        if ($level === null) {
            return $parsed ? [$parsed[0], $section ?? $parsed[1]] : [null, null];
        }

        return [$level, $section ?? self::NO_SECTION];
    }

    /** @return list<string> eksik sınıf adları ("9-A", "11"…) */
    public function missing(int $termId): array
    {
        $have = $this->groups($termId)->keys()->all();
        $out = [];
        foreach ($this->structure()['levels'] as $l) {
            foreach ($l['sections'] as $s) {
                if (! in_array(self::key($l['grade'], $s['code']), $have, true)) {
                    $out[] = self::className($l['grade'], $s['code']);
                }
            }
        }

        return $out;
    }

    /**
     * Yapıyı döneme uygulama planı (yazmaz): oluşturulacak sınıflar ve mevcut sınıflarda değişecek alanlar.
     *
     * @return list<array>
     */
    public function plan(AcademicTerm $term): array
    {
        $out = [];
        foreach ($this->structure()['levels'] as $l) {
            foreach ($l['sections'] as $s) {
                $name = self::className($l['grade'], $s['code']);
                $existing = $this->findExisting($term, $l['grade'], $s['code']);
                $row = ['key' => self::key($l['grade'], $s['code']), 'name' => $name, 'grade' => $l['grade'], 'section' => $s['code'], 'track' => $s['track'], 'capacity' => $s['capacity']];
                if (! $existing) {
                    $out[] = $row + ['action' => 'create', 'class_group_id' => null, 'current_name' => null, 'changes' => []];

                    continue;
                }
                $changes = $this->diff($existing, $s);
                $inactive = $existing->trashed() || ! $existing->is_active;
                $out[] = $row + ['action' => $inactive ? 'reactivate' : ($changes ? 'update' : 'ok'), 'class_group_id' => $existing->id, 'current_name' => $existing->name, 'changes' => $changes];
            }
        }

        return $out;
    }

    /**
     * Eksik sınıfları oluşturur, eşleşen sınıfların seviye/şube alanlarını doldurur.
     * $updateExisting: mevcut sınıflara yapıdaki kapasite/alan/program/şablon/derslik/rehber değerlerini de yazar.
     * Transaction çağıranın sorumluluğundadır.
     *
     * @return array{created:list<string>, adopted:list<string>, reactivated:list<string>, updated:list<string>, programs_created:list<string>, group_ids:array<string,int>}
     */
    public function ensure(AcademicTerm $term, bool $dryRun = false, bool $updateExisting = false): array
    {
        $created = $adopted = $reactivated = $updated = $programsCreated = [];
        $ids = [];

        foreach ($this->structure()['levels'] as $l) {
            $level = $l['grade'];
            foreach ($l['sections'] as $spec) {
                $section = $spec['code'];
                $name = self::className($level, $section);
                $dbSection = $section === self::NO_SECTION ? null : $section;
                $existing = $this->findExisting($term, $level, $section);
                if ($existing) {
                    $changes = [];
                    if ($existing->grade_level !== $level || $existing->section !== $dbSection) {
                        $changes['grade_level'] = $level;
                        $changes['section'] = $dbSection;
                        $adopted[] = $name;
                    }
                    if ($existing->trashed() || ! $existing->is_active) {
                        $reactivated[] = $name;
                    }
                    $attrDiff = $updateExisting ? $this->diff($existing, $spec) : [];
                    if ($attrDiff) {
                        $updated[] = $name;
                    }
                    if (! $dryRun) {
                        if ($existing->trashed()) {
                            $existing->restore();
                        }
                        foreach ($attrDiff as $c) {
                            if ($c['field'] !== 'time_template_ids') {
                                $changes[$c['field']] = $c['to'];
                            }
                        }
                        $existing->forceFill($changes + ['is_active' => true])->save();
                        if (in_array('time_template_ids', array_column($attrDiff, 'field'), true)) {
                            $existing->timeTemplates()->sync($this->validTemplates($spec['time_template_ids']));
                        }
                        if ($attrDiff) {
                            Audit::log('class_group.structure_updated', "{$existing->name} sınıfını sınıf yapısına göre güncelledi: ".implode(', ', array_column($attrDiff, 'label')).'.', $existing,
                                ['before' => array_column($attrDiff, 'from', 'field'), 'after' => array_column($attrDiff, 'to', 'field')]);
                        }
                    }
                    $ids[self::key($level, $section)] = $existing->id;

                    continue;
                }

                $created[] = $name;
                if ($dryRun) {
                    continue;
                }
                $program = $this->programForSpec($level, $spec, $programsCreated);
                $group = ClassGroup::query()->create([
                    'academic_term_id' => $term->id, 'program_id' => $program->id, 'name' => $name, 'capacity' => $spec['capacity'], 'is_active' => true,
                    'homeroom_classroom_id' => $this->validRoom($spec['homeroom_classroom_id']), 'advisor_teacher_id' => $this->validTeacher($spec['advisor_teacher_id']),
                ]);
                $group->forceFill(['grade_level' => $level, 'section' => $dbSection, 'track' => $spec['track'], 'short_name' => $spec['short_name'], 'color' => $spec['color']])->save();
                if ($spec['time_template_ids']) {
                    $group->timeTemplates()->sync($this->validTemplates($spec['time_template_ids']));
                }
                $ids[self::key($level, $section)] = $group->id;
                $track = $spec['track'] ? ', alan '.self::TRACKS[$spec['track']] : '';
                Audit::log('class_group.created', "{$name} sınıfını oluşturdu (kontenjan {$spec['capacity']}{$track}, {$term->name}).", $group);
            }
        }

        if ($dryRun) {
            foreach ($this->structure()['levels'] as $l) {
                foreach ($l['sections'] as $spec) {
                    if ($spec['program_id'] || ! in_array(self::className($l['grade'], $spec['code']), $created, true)) {
                        continue;
                    }
                    $code = self::LEVEL_PROGRAMS[$l['grade']][0] ?? 'ARA';
                    $trackCode = self::TRACK_PROGRAMS[$spec['track'] ?? ''] ?? null;
                    if (! ($trackCode && Program::query()->where('code', $trackCode)->exists()) && ! Program::query()->where('code', $code)->exists()) {
                        $programsCreated[] = $code;
                    }
                }
            }
        }

        return ['created' => $created, 'adopted' => $adopted, 'reactivated' => $reactivated, 'updated' => $updated,
            'programs_created' => array_values(array_unique($programsCreated)), 'group_ids' => $ids];
    }

    private function findExisting(AcademicTerm $term, int $level, string $section): ?ClassGroup
    {
        $dbSection = $section === self::NO_SECTION ? null : $section;
        $byFields = ClassGroup::query()->withTrashed()->where('academic_term_id', $term->id)->where('grade_level', $level)
            ->when($dbSection === null, fn ($q) => $q->whereNull('section'), fn ($q) => $q->where('section', $dbSection))
            ->orderByRaw('deleted_at IS NOT NULL')->orderByDesc('is_active')->orderBy('id')->first();

        return $byFields ?? ClassGroup::query()->withTrashed()->where('academic_term_id', $term->id)->where('name', self::className($level, $section))->first();
    }

    /** Mevcut sınıf ↔ şube tanımı farkı (yalnız tanımda dolu olan alanlar karşılaştırılır; kapasite ve alan her zaman). */
    private function diff(ClassGroup $g, array $spec): array
    {
        $out = [];
        $add = function (string $field, string $label, $from, $to) use (&$out) {
            if ($from !== $to) {
                $out[] = ['field' => $field, 'label' => $label, 'from' => $from, 'to' => $to];
            }
        };
        $add('capacity', 'kapasite', (int) $g->capacity, $spec['capacity']);
        $add('track', 'alan', $g->track, $spec['track']);
        if ($spec['program_id']) {
            $add('program_id', 'program', (int) $g->program_id, $spec['program_id']);
        }
        if ($spec['homeroom_classroom_id']) {
            $add('homeroom_classroom_id', 'ana derslik', $g->homeroom_classroom_id ? (int) $g->homeroom_classroom_id : null, $spec['homeroom_classroom_id']);
        }
        if ($spec['advisor_teacher_id']) {
            $add('advisor_teacher_id', 'rehber öğretmen', $g->advisor_teacher_id ? (int) $g->advisor_teacher_id : null, $spec['advisor_teacher_id']);
        }
        if ($spec['short_name'] !== null) {
            $add('short_name', 'kısa ad', $g->short_name, $spec['short_name']);
        }
        if ($spec['color'] !== null) {
            $add('color', 'renk', $g->color, $spec['color']);
        }
        if ($spec['time_template_ids']) {
            $current = $g->timeTemplates()->pluck('time_templates.id')->map(fn ($v) => (int) $v)->sort()->values()->all();
            $wanted = $spec['time_template_ids'];
            sort($wanted);
            if ($current !== $wanted) {
                $out[] = ['field' => 'time_template_ids', 'label' => 'zaman şablonu', 'from' => $current, 'to' => $wanted];
            }
        }

        return $out;
    }

    private function validTemplates(array $ids): array
    {
        return $ids ? TimeTemplate::query()->whereIn('id', $ids)->pluck('id')->all() : [];
    }

    private function validRoom(?int $id): ?int
    {
        return $id && Classroom::query()->whereKey($id)->exists() ? $id : null;
    }

    private function validTeacher(?int $id): ?int
    {
        return $id && Teacher::query()->whereKey($id)->exists() ? $id : null;
    }

    /** Şubenin programı: tanımdaki program > alanın programı > seviye programı (yoksa oluşturulur). */
    public function programForSpec(int $level, array $spec, array &$created = []): Program
    {
        if (! empty($spec['program_id']) && ($p = Program::query()->find($spec['program_id']))) {
            return $p;
        }
        $trackCode = self::TRACK_PROGRAMS[$spec['track'] ?? ''] ?? null;
        if ($level >= 12 && $trackCode && ($p = Program::query()->where('code', $trackCode)->first())) {
            return $p;
        }
        if ($level <= 8 && ($p = Program::query()->where('code', 'LGS')->first())) {
            return $p;
        }

        return $this->programFor($level, $created);
    }

    /** Seviyenin programı; yoksa oluşturulur (12/Mezun için YKS: dersleri "YKS Mezun"/"AYT Sayısal" programından kopyalanır). */
    public function programFor(int $level, array &$created = []): Program
    {
        [$code, $name, $track] = self::LEVEL_PROGRAMS[$level] ?? ['ARA', 'Ara Sınıf', 'TYT'];
        $program = Program::query()->where('code', $code)->first();
        if ($program) {
            return $program;
        }

        $program = Program::query()->create(['code' => $code, 'name' => $name, 'exam_track' => $track, 'kind' => 'group', 'color' => 'indigo', 'is_active' => true]);
        $source = Program::query()->whereIn('code', in_array($code, ['YKS', 'MEZUN'], true) ? ['MEZUN', 'AYT_SAY', 'TYT'] : ['TYT'])->whereKeyNot($program->id)
            ->orderByRaw("FIELD(code, 'MEZUN', 'AYT_SAY', 'TYT')")->first();
        if ($source) {
            $rows = DB::table('program_subject')->where('program_id', $source->id)->get(['subject_id', 'weekly_hours', 'curriculum']);
            foreach ($rows as $r) {
                DB::table('program_subject')->insert(['program_id' => $program->id, 'subject_id' => $r->subject_id, 'weekly_hours' => $r->weekly_hours, 'curriculum' => $r->curriculum]);
            }
        }
        $created[] = $code;
        Audit::log('program.created', "Sınıf yapısı için \"{$name}\" programını oluşturdu.", $program);

        return $program;
    }
}
