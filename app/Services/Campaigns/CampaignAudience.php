<?php

namespace App\Services\Campaigns;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Lead;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\Sensitive;
use Illuminate\Support\Arr;

/**
 * Toplu gönderim hedef kitlesini kişilere çözer.
 *
 * audience = {
 *   groups: students|guardians|teachers|employees|leads|manual …,
 *   student_statuses: ['active', …]   (öğrenci + veli için; boşsa aktif),
 *   class_group_ids: [..], program_ids: [..]   (öğrenci + veli için),
 *   lead_stages: [..]   (boşsa açık adaylar),
 *   manual: [{name, phone, email}]
 * }
 *
 * Çıktı kişi listesidir (kanal bağımsız); kanal/izin/tekrar kararı CampaignPlanner'dadır.
 */
final class CampaignAudience
{
    public const GROUPS = [
        'students' => 'Öğrenciler',
        'guardians' => 'Veliler',
        'teachers' => 'Öğretmenler',
        'employees' => 'Personel',
        'leads' => 'Ön kayıt adayları',
        'manual' => 'Elle eklenenler',
    ];

    public const OPEN_LEAD_STAGES = ['new', 'called', 'meeting_scheduled', 'met', 'offered', 'undecided', 'call_again'];

    public const MAX_MANUAL = 1000;

    /**
     * @return list<array{group:string, type:?string, id:?int, model:?object, name:string, first_name:string, last_name:string,
     *   phone:?string, email:?string, student_id:?int, vars:array<string,string>}>
     */
    public function resolve(array $audience): array
    {
        $groups = array_values(array_intersect(array_keys(self::GROUPS), (array) ($audience['groups'] ?? [])));
        $people = [];

        $needStudents = in_array('students', $groups, true) || in_array('guardians', $groups, true);
        $students = $needStudents ? $this->students($audience) : collect();

        if (in_array('students', $groups, true)) {
            foreach ($students as $s) {
                $people[] = $this->person('students', 'student', $s, $s->first_name, $s->last_name,
                    Sensitive::normalizePhone($s->whatsapp_phone ?: $s->phone), $s->email, $s->id,
                    ['ogrenci_ad' => (string) $s->full_name, 'sinif' => $this->classNames($s)]);
            }
        }

        if (in_array('guardians', $groups, true)) {
            // Aynı veli birden çok öğrencinin velisi olabilir: tek kişi, çocuk adları birleşir
            $byGuardian = [];
            foreach ($students as $s) {
                $guardians = $s->guardians->filter(fn ($g) => (bool) $g->pivot->receives_notifications);
                if ($guardians->isEmpty()) {
                    $guardians = $s->guardians->filter(fn ($g) => (bool) $g->pivot->is_primary);
                }
                foreach ($guardians as $g) {
                    $byGuardian[$g->id] ??= ['model' => $g, 'children' => [], 'classes' => [], 'student_id' => $s->id];
                    $byGuardian[$g->id]['children'][] = (string) $s->full_name;
                    if ($c = $this->classNames($s)) {
                        $byGuardian[$g->id]['classes'][] = $c;
                    }
                }
            }
            foreach ($byGuardian as $row) {
                /** @var Guardian $g */
                $g = $row['model'];
                $people[] = $this->person('guardians', 'guardian', $g, $g->first_name, $g->last_name, $g->messagingPhone(), $g->email, $row['student_id'], [
                    'ogrenci_ad' => implode(', ', array_unique($row['children'])),
                    'sinif' => implode(', ', array_unique($row['classes'])),
                ]);
            }
        }

        if (in_array('teachers', $groups, true)) {
            foreach (Teacher::query()->where('is_active', true)->orderBy('first_name')->get() as $t) {
                $people[] = $this->person('teachers', 'teacher', $t, $t->first_name, $t->last_name,
                    Sensitive::normalizePhone($t->whatsapp_phone ?: $t->phone), $t->email, null, []);
            }
        }

        if (in_array('employees', $groups, true)) {
            foreach (Employee::query()->where('is_active', true)->orderBy('first_name')->get() as $e) {
                $people[] = $this->person('employees', 'employee', $e, $e->first_name, $e->last_name,
                    Sensitive::normalizePhone($e->phone), $e->email, null, []);
            }
        }

        if (in_array('leads', $groups, true)) {
            $stages = array_values(array_intersect(array_keys(Lead::STAGES), (array) ($audience['lead_stages'] ?? [])));
            $leads = Lead::query()->whereIn('stage', $stages ?: self::OPEN_LEAD_STAGES)->orderBy('first_name')->get();
            foreach ($leads as $l) {
                $people[] = $this->person('leads', 'lead', $l, $l->first_name, $l->last_name,
                    Sensitive::normalizePhone($l->phone ?: $l->guardian_phone), $l->email, null, ['ogrenci_ad' => (string) $l->full_name]);
            }
        }

        if (in_array('manual', $groups, true)) {
            foreach (array_slice((array) ($audience['manual'] ?? []), 0, self::MAX_MANUAL) as $row) {
                $name = trim((string) ($row['name'] ?? ''));
                [$first, $last] = self::splitName($name);
                $people[] = [
                    'group' => 'manual', 'type' => null, 'id' => null, 'model' => null,
                    'name' => $name !== '' ? $name : 'İsimsiz', 'first_name' => $first, 'last_name' => $last,
                    'phone' => Sensitive::normalizePhone(isset($row['phone']) ? (string) $row['phone'] : null),
                    'email' => ($e = trim((string) ($row['email'] ?? ''))) !== '' ? mb_strtolower($e) : null,
                    'student_id' => null, 'vars' => [],
                ];
            }
        }

        return $people;
    }

    /** @return \Illuminate\Support\Collection<int, Student> */
    private function students(array $audience)
    {
        $statuses = array_values(array_intersect(array_keys(Student::STATUSES), (array) ($audience['student_statuses'] ?? [])));
        $classIds = array_map('intval', (array) ($audience['class_group_ids'] ?? []));
        $programIds = array_map('intval', (array) ($audience['program_ids'] ?? []));

        return Student::query()
            ->whereIn('status', $statuses ?: ['active'])
            ->when($classIds, fn ($q) => $q->whereHas('currentClassGroups', fn ($w) => $w->whereIn('class_groups.id', $classIds)))
            ->when($programIds, fn ($q) => $q->whereHas('currentClassGroups', fn ($w) => $w->whereIn('class_groups.program_id', $programIds)))
            ->with(['guardians', 'currentClassGroups' => fn ($q) => $q->select('class_groups.id', 'class_groups.name')])
            ->orderBy('full_name')
            ->get();
    }

    private function classNames(Student $s): string
    {
        return $s->relationLoaded('currentClassGroups') ? $s->currentClassGroups->pluck('name')->implode(', ') : '';
    }

    private function person(string $group, string $type, object $model, ?string $first, ?string $last, ?string $phone, ?string $email, ?int $studentId, array $vars): array
    {
        $first = trim((string) $first);
        $last = trim((string) $last);

        return [
            'group' => $group, 'type' => $type, 'id' => (int) $model->getKey(), 'model' => $model,
            'name' => trim($first.' '.$last), 'first_name' => $first, 'last_name' => $last,
            'phone' => $phone, 'email' => $email ? mb_strtolower(trim($email)) : null,
            'student_id' => $studentId, 'vars' => $vars,
        ];
    }

    /** @return array{0:string,1:string} */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        if (count($parts) <= 1) {
            return [(string) Arr::first($parts, null, ''), ''];
        }
        $last = array_pop($parts);

        return [implode(' ', $parts), (string) $last];
    }

    /**
     * Elle yapıştırılan satırları ayrıştırır: "Ad Soyad; 0532…; ad@x.com" (ayraç ; , ya da sekme).
     *
     * @return list<array{name:string, phone:?string, email:?string}>
     */
    public static function parseManual(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $name = [];
            $phone = null;
            $email = null;
            foreach (preg_split('/[;\t,]+/', $line) as $cell) {
                $cell = trim($cell);
                if ($cell === '') {
                    continue;
                }
                if (! $email && filter_var($cell, FILTER_VALIDATE_EMAIL)) {
                    $email = $cell;
                } elseif (! $phone && preg_match('/^[+\d][\d\s()\-]{8,}$/', $cell)) {
                    $phone = $cell;
                } else {
                    $name[] = $cell;
                }
            }
            if ($phone || $email) {
                $out[] = ['name' => implode(' ', $name), 'phone' => $phone, 'email' => $email];
            }
        }

        return array_slice($out, 0, self::MAX_MANUAL);
    }
}
