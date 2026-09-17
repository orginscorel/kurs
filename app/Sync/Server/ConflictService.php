<?php

namespace App\Sync\Server;

use App\Exceptions\BusinessRuleException;
use App\Support\Audit;
use App\Sync\ChangeRecorder;
use App\Sync\ModelMap;
use App\Sync\Models\SyncConflict;
use App\Sync\Models\SyncDevice;
use App\Sync\RowCodec;
use App\Sync\SyncContext;
use App\Sync\SyncRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Çakışma kayıtları ve çözümü. Son-yazan-kazanır otomatik uygulanır; kayıt incelemeye açık kalır
 * ("Kazanan kalsın" / "Diğer değeri uygula"). Finans kayıtları mutabakat kuyruğudur (asla ezilmez).
 */
class ConflictService
{
    public function __construct(
        private readonly RowCodec $codec,
        private readonly ChangeRecorder $recorder,
        private readonly SyncContext $context,
    ) {}

    public function record(SyncDevice $device, array $data): SyncConflict
    {
        $rowId = $data['row_id'] ?? null;
        unset($data['row_id']);
        $data['row_label'] ??= $rowId ? $this->label($data['table_name'], (int) $rowId) : null;

        return SyncConflict::query()->create($data + [
            'branch_id' => $device->branch_id,
            'device_id' => $device->id,
            'status' => 'open',
        ]);
    }

    /** Sunucunun reddettiği değişiklik: kullanıcıya görünür iz. */
    public function rejected(SyncDevice $device, array $change, array $result): void
    {
        $table = (string) ($change['table'] ?? ($change['command'] ?? '?'));
        $this->record($device, [
            'kind' => 'rejected',
            'table_name' => mb_substr($table, 0, 64),
            'row_uuid' => isset($change['row']) && preg_match('/^[0-9a-f-]{36}$/i', (string) $change['row']) ? $change['row'] : null,
            'device_value' => json_encode(array_intersect_key($change, array_flip(['op', 'command', 'fields', 'args'])), JSON_UNESCAPED_UNICODE),
            'server_value' => null,
            'winner' => 'server',
            'change_uuid' => isset($change['id']) && preg_match('/^[0-9a-f-]{36}$/i', (string) $change['id']) ? $change['id'] : null,
            'note' => mb_substr(($result['message'] ?? 'Reddedildi').' ('.($result['code'] ?? '').')', 0, 500),
        ]);
    }

    /** İnsan okunur satır etiketi. */
    public function label(string $table, int $id): ?string
    {
        $row = DB::table($table)->where('id', $id)->first();
        if (! $row) {
            return null;
        }
        $r = (array) $row;
        $name = fn (?int $studentId) => $studentId ? DB::table('students')->where('id', $studentId)->value('full_name') : null;

        $label = match ($table) {
            'students' => trim(($r['full_name'] ?? '').' · '.($r['student_no'] ?? '')),
            'guardians', 'teachers', 'employees', 'leads' => trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? '')),
            'attendances' => trim(($name($r['student_id'] ?? null) ?? 'Öğrenci').' · '.($r['date'] ?? '')),
            'payments' => trim(($r['receipt_no'] ?? '').' · '.($name($r['student_id'] ?? null) ?? '')),
            'enrollments' => trim(($r['enrollment_no'] ?? '').' · '.($name($r['student_id'] ?? null) ?? '')),
            default => $r['name'] ?? $r['title'] ?? (isset($r['student_id']) ? $name($r['student_id']) : null),
        };

        return $label ? mb_substr((string) $label, 0, 200) : $table.' #'.$id;
    }

    /**
     * Çözüm: keep (kazanan kalsın), apply_other (kaybeden değeri uygula), reconciled (finans mutabakatı tamam).
     */
    public function resolve(SyncConflict $conflict, string $action, ?string $note = null): SyncConflict
    {
        if ($conflict->status !== 'open') {
            throw new BusinessRuleException('Bu çakışma zaten çözülmüş.', 'already_resolved');
        }

        return DB::transaction(function () use ($conflict, $action, $note) {
            if ($action === 'apply_other') {
                if ($conflict->kind !== 'field' && $conflict->kind !== 'attendance') {
                    throw new BusinessRuleException('Bu kayıt türünde diğer değer uygulanamaz.', 'not_applicable');
                }
                $value = $conflict->winner === 'device' ? $conflict->server_value : $conflict->device_value;
                $this->applyValue($conflict, $value);
            }

            $conflict->forceFill([
                'status' => $action === 'dismiss' ? 'dismissed' : 'resolved',
                'resolution' => $action,
                'resolved_by' => Auth::id(),
                'resolved_at' => now(),
                'note' => $note ? mb_substr(trim(($conflict->note ?? '')."\n".$note), 0, 500) : $conflict->note,
            ])->save();

            Audit::log('sync.conflict_resolved', sprintf('%s eşitleme çakışmasını çözdü (%s).', $conflict->row_label ?: $conflict->table_name, match ($action) {
                'apply_other' => 'diğer değer uygulandı', 'reconciled' => 'mutabakat tamam', 'dismiss' => 'yok sayıldı', default => 'kazanan korundu',
            }));

            return $conflict;
        });
    }

    /** Alanı seçilen değere çeker (web kaynaklı değişiklik olarak tüm cihazlara iner). */
    private function applyValue(SyncConflict $conflict, ?string $wireValue): void
    {
        $def = SyncRegistry::get($conflict->table_name);
        $row = DB::table($conflict->table_name)->where('uuid', $conflict->row_uuid)->first();
        if (! $def || ! $row || ! $conflict->field) {
            throw new BusinessRuleException('Kayıt artık yok; değer uygulanamadı.', 'row_missing');
        }
        [$decoded, $missing] = $this->codec->decode($conflict->table_name, [$conflict->field => $wireValue]);
        if ($missing !== []) {
            throw new BusinessRuleException('Bağlı kayıt bulunamadı; değer uygulanamadı.', 'missing_reference');
        }
        $class = ModelMap::classFor($conflict->table_name);
        if ($class) {
            $model = $class::query()->withoutGlobalScopes()->find($row->id);
            $model->setRawAttributes(array_merge($model->getAttributes(), $decoded));
            $model->save();   // ChangeRecorder normal olarak kaydeder (kaynak: web)
        } else {
            DB::table($conflict->table_name)->where('id', $row->id)->update($decoded);
            $this->recorder->write($conflict->table_name, $conflict->row_uuid, 'update', [$conflict->field => $wireValue],
                $conflict->branch_id, ['source' => 'conflict']);
        }
    }
}
