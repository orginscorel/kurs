<?php

namespace App\Services\Placement;

/**
 * Bir toplu işlemin yazdığı her şeyin "önceki hali". placement_runs.snapshot olarak saklanır,
 * geri alma bununla yapılır. Aynı kayıt birden çok kez değişirse yalnız ilk (en eski) hal tutulur.
 */
final class ChangeSet
{
    /** @var array<int, ?string> membership id => önceki left_on */
    public array $closed = [];
    /** @var list<int> oluşturulan membership id'leri */
    public array $created = [];
    /** @var array<int, array{class_group_id:?int, status:string, ended_on:?string}> */
    public array $enrollments = [];
    /** @var array<int, array{before:array, after:array}> öğrenci alanları (status, school_grade, field) */
    public array $students = [];
    /** @var list<int> */
    public array $waitlistCreated = [];
    /** @var array<int, array{status:string, resolved_at:?string}> */
    public array $waitlistResolved = [];
    /** @var array<int, array{is_active:bool}> */
    public array $groups = [];
    /** @var array<int, ?string> lesson_schedule id => önceki valid_until */
    public array $schedules = [];
    /** @var array<int, array{status:string, cancel_reason:?string}> */
    public array $sessions = [];
    /** @var array<int, ?string> tag attach kayıtları yok; öğrenci pin durumları membership ile gider */
    public array $events = [];

    public function recordStudent(int $id, array $before, array $after): void
    {
        if (isset($this->students[$id])) {
            $this->students[$id]['after'] = array_merge($this->students[$id]['after'], $after);

            return;
        }
        $this->students[$id] = ['before' => $before, 'after' => $after];
    }

    public function isEmpty(): bool
    {
        return ! $this->closed && ! $this->created && ! $this->students && ! $this->waitlistCreated && ! $this->groups && ! $this->schedules && ! $this->sessions;
    }

    public function toArray(): array
    {
        return [
            'closed' => $this->closed, 'created' => $this->created, 'enrollments' => $this->enrollments, 'students' => $this->students,
            'waitlist_created' => $this->waitlistCreated, 'waitlist_resolved' => $this->waitlistResolved,
            'groups' => $this->groups, 'schedules' => $this->schedules, 'sessions' => $this->sessions,
        ];
    }

    public static function fromArray(array $a): self
    {
        $cs = new self;
        // JSON'da sayısal anahtarlar string döner → int'e çevir
        $intKeys = fn (array $arr) => array_combine(array_map('intval', array_keys($arr)), array_values($arr)) ?: [];
        $cs->closed = $intKeys($a['closed'] ?? []);
        $cs->created = array_map('intval', $a['created'] ?? []);
        $cs->enrollments = $intKeys($a['enrollments'] ?? []);
        $cs->students = $intKeys($a['students'] ?? []);
        $cs->waitlistCreated = array_map('intval', $a['waitlist_created'] ?? []);
        $cs->waitlistResolved = $intKeys($a['waitlist_resolved'] ?? []);
        $cs->groups = $intKeys($a['groups'] ?? []);
        $cs->schedules = $intKeys($a['schedules'] ?? []);
        $cs->sessions = $intKeys($a['sessions'] ?? []);

        return $cs;
    }
}
