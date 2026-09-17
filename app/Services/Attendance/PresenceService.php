<?php

namespace App\Services\Attendance;

use App\Events\StudentEnteredBuilding;
use App\Events\StudentLeftBuilding;
use App\Models\ActivityFeed;
use App\Models\AttendanceEvent;
use App\Models\DailyPresence;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Support\BranchContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Giriş/çıkış olayı alma. Donanım köprüsü, QR, öğretmen ve yönetici aynı yoldan geçer.
 *
 * - İdempotent: aynı idempotency_key ikinci kez gelirse "duplicate" döner, hiçbir şey değişmez.
 * - Çevrimdışı kuyruktan gelen eski olaylar occurred_at sırasına göre işlenir.
 * - event_type "AUTO" ise öğrencinin içeride olup olmamasına göre GİRİŞ/ÇIKIŞ belirlenir.
 */
class PresenceService
{
    /**
     * @param array{identifier?:?string, identifier_kind?:?string, student_id?:?int, event_type:string,
     *              occurred_at:string, idempotency_key:string, source?:?string} $payload
     * @return array{status:'accepted'|'duplicate'|'unmatched'|'debounced', event_id?:int, student?:string, event_type?:string}
     */
    public function ingest(array $payload, ?Device $device = null): array
    {
        $branchId = $device?->branch_id ?? app(BranchContext::class)->require();

        return app(BranchContext::class)->run($branchId, function () use ($payload, $device, $branchId) {
            if (AttendanceEvent::query()->where('idempotency_key', $payload['idempotency_key'])->exists()) {
                return ['status' => 'duplicate'];
            }

            $student = $this->resolveStudent($payload, $device);
            $occurredAt = CarbonImmutable::parse($payload['occurred_at'])->setTimezone(config('app.timezone'));
            $source = $payload['source'] ?? ($device?->kind ?? 'manual');

            if (! $student) {
                $this->storeEvent($branchId, $device, null, strtoupper($payload['event_type']), $source, $occurredAt, $payload, false);

                return ['status' => 'unmatched'];
            }

            return DB::transaction(function () use ($student, $payload, $device, $branchId, $occurredAt, $source) {
                $presence = DailyPresence::query()
                    ->where('student_id', $student->id)->where('date', $occurredAt->toDateString())
                    ->lockForUpdate()->first()
                    ?? DailyPresence::query()->create(['student_id' => $student->id, 'date' => $occurredAt->toDateString()]);

                // Parmağını art arda iki kez okutan öğrenci: kısa aralıktaki ikinci okutma yok sayılır.
                $debounce = (int) Settings::get('attendance.min_minutes_between_events', 2);
                if ($presence->last_event_at && abs($presence->last_event_at->diffInSeconds($occurredAt)) < $debounce * 60) {
                    $this->storeEvent($branchId, $device, $student, 'IGNORED', $source, $occurredAt, $payload, true);

                    return ['status' => 'debounced', 'student' => $student->full_name];
                }

                $type = strtoupper($payload['event_type']);
                if ($type === 'AUTO') {
                    $type = $presence->is_inside ? 'EXIT' : 'ENTRY';
                }
                if ($device && $device->direction !== 'both') {
                    $type = $device->direction === 'entry' ? 'ENTRY' : 'EXIT';
                }

                $event = $this->storeEvent($branchId, $device, $student, $type, $source, $occurredAt, $payload, true);
                $this->applyToPresence($presence, $type, $occurredAt);

                ActivityFeed::query()->create([
                    'kind' => $type === 'ENTRY' ? 'entry' : 'exit',
                    'message' => $student->full_name.($type === 'ENTRY' ? ' kuruma giriş yaptı' : ' kurumdan çıkış yaptı'),
                    'student_id' => $student->id,
                    'subject_type' => 'student',
                    'subject_id' => $student->id,
                    'meta' => ['device' => $device?->name, 'source' => $source],
                    'occurred_at' => $occurredAt,
                ]);

                // Yalnızca "canlı" olaylar veliye bildirim üretir; 6 saatten eski senkron kayıtları bildirim göndermez.
                $isFresh = $occurredAt->gt(now()->subHours(6));
                DB::afterCommit(function () use ($type, $event, $isFresh) {
                    event($type === 'ENTRY'
                        ? new StudentEnteredBuilding($event->id, $isFresh)
                        : new StudentLeftBuilding($event->id, $isFresh));
                });

                return ['status' => 'accepted', 'event_id' => $event->id, 'student' => $student->full_name, 'event_type' => $type];
            });
        });
    }

    private function applyToPresence(DailyPresence $presence, string $type, CarbonImmutable $at): void
    {
        $data = ['last_event_at' => $at];

        if ($type === 'ENTRY') {
            $data['is_inside'] = true;
            if (! $presence->first_entry_at || $at->lt($presence->first_entry_at)) {
                $data['first_entry_at'] = $at;
            }
        } else {
            $data['is_inside'] = false;
            $data['last_exit_at'] = $at;

            // Kalma süresi: son girişten bu çıkışa kadar geçen süre eklenir.
            $lastEntry = AttendanceEvent::query()
                ->where('student_id', $presence->student_id)
                ->where('event_type', 'ENTRY')
                ->whereDate('occurred_at', $at->toDateString())
                ->where('occurred_at', '<=', $at)
                ->latest('occurred_at')
                ->value('occurred_at');

            if ($lastEntry) {
                $data['minutes_inside'] = $presence->minutes_inside + (int) floor(CarbonImmutable::parse($lastEntry)->diffInMinutes($at, true));
            }
        }

        $presence->forceFill($data)->save();
    }

    /**
     * Eşleşmeyen bir okutmayı (AttendanceEvent) sonradan bir öğrenciye bağlar.
     * Gelecekteki okutmaların otomatik eşleşmesi için aynı tanımlayıcıyla bir
     * DeviceIdentity de oluşturur (yoksa). Geriye dönük diğer olayları değiştirmez.
     *
     * @return array{status:string, event_id:int, student:string}
     */
    public function matchEvent(AttendanceEvent $event, Student $student, ?int $recordedBy = null): array
    {
        if ($event->is_matched && $event->student_id) {
            return ['status' => 'already_matched', 'event_id' => $event->id, 'student' => $student->full_name];
        }

        $branchId = $event->branch_id;

        return app(BranchContext::class)->run($branchId, function () use ($event, $student, $recordedBy, $branchId) {
            return DB::transaction(function () use ($event, $student, $recordedBy, $branchId) {
                if ($event->raw_identifier) {
                    $kind = match ($event->source) {
                        'rfid' => 'card',
                        'qr' => 'qr',
                        default => 'fingerprint',
                    };

                    DeviceIdentity::query()->withoutGlobalScope('branch')->firstOrCreate(
                        ['branch_id' => $branchId, 'kind' => $kind, 'identifier' => $event->raw_identifier],
                        ['person_type' => 'student', 'person_id' => $student->id, 'is_active' => true],
                    );
                }

                $occurredAt = CarbonImmutable::parse($event->occurred_at);
                $presence = DailyPresence::query()->withoutGlobalScope('branch')
                    ->where('student_id', $student->id)->where('date', $occurredAt->toDateString())
                    ->lockForUpdate()->first()
                    ?? DailyPresence::query()->create(['branch_id' => $branchId, 'student_id' => $student->id, 'date' => $occurredAt->toDateString()]);

                $type = $event->event_type === 'IGNORED' ? 'ENTRY' : $event->event_type;
                $this->applyToPresence($presence, $type, $occurredAt);

                $event->forceFill(['student_id' => $student->id, 'person_type' => 'student', 'person_id' => $student->id, 'is_matched' => true])->save();

                ActivityFeed::query()->create([
                    'branch_id' => $branchId,
                    'kind' => $type === 'ENTRY' ? 'entry' : 'exit',
                    'message' => "{$student->full_name} okutması sonradan eşleştirildi (".($type === 'ENTRY' ? 'giriş' : 'çıkış').').',
                    'student_id' => $student->id,
                    'subject_type' => 'student',
                    'subject_id' => $student->id,
                    'meta' => ['matched_by' => $recordedBy, 'event_id' => $event->id],
                    'occurred_at' => now(),
                ]);

                return ['status' => 'matched', 'event_id' => $event->id, 'student' => $student->full_name];
            });
        });
    }

    private function resolveStudent(array $payload, ?Device $device): ?Student
    {
        if (! empty($payload['student_id'])) {
            return Student::query()->find($payload['student_id']);
        }

        if (empty($payload['identifier'])) {
            return null;
        }

        $kind = $payload['identifier_kind'] ?? match ($device?->kind) {
            'rfid' => 'card',
            'qr' => 'qr',
            default => 'fingerprint',
        };

        $identity = DeviceIdentity::query()
            ->where('kind', $kind)->where('identifier', $payload['identifier'])->where('is_active', true)
            ->where('person_type', 'student')
            ->first();

        return $identity ? Student::query()->find($identity->person_id) : null;
    }

    private function storeEvent(int $branchId, ?Device $device, ?Student $student, string $type, string $source, CarbonImmutable $at, array $payload, bool $matched): AttendanceEvent
    {
        try {
            return AttendanceEvent::query()->create([
                'branch_id' => $branchId,
                'device_id' => $device?->id,
                'student_id' => $student?->id,
                'person_type' => $student ? 'student' : null,
                'person_id' => $student?->id,
                'event_type' => $type,
                'source' => $source,
                'occurred_at' => $at,
                'received_at' => now(),
                'idempotency_key' => $payload['idempotency_key'],
                'raw_identifier' => $payload['identifier'] ?? null,
                'is_matched' => $matched,
                'recorded_by' => Auth::id(),
            ]);
        } catch (QueryException $e) {
            // Eşzamanlı ikinci teslim: tekil indeks ihlali → yinelenen olay.
            if (str_contains($e->getMessage(), 'idempotency_key')) {
                throw new DuplicateEventException();
            }
            throw $e;
        }
    }
}
