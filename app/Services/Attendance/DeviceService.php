<?php

namespace App\Services\Attendance;

use App\Exceptions\BusinessRuleException;
use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\Student;
use App\Support\Audit;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

/**
 * Cihaz CRUD + jeton yaşam döngüsü + kimlik eşleme (öğrenci ↔ cihaz kullanıcı no / kart UID).
 * KVKK: yalnız cihazın kendi kullanıcı numarası/kart UID'si tutulur, ham biyometrik şablon değil.
 */
class DeviceService
{
    public function create(array $data): Device
    {
        $device = Device::query()->create([
            'branch_id' => app(BranchContext::class)->require(),
            'name' => $data['name'],
            'kind' => $data['kind'],
            'location' => $data['location'] ?? null,
            'direction' => $data['direction'] ?? 'both',
            'serial_no' => $data['serial_no'] ?? null,
            'api_token_hash' => '',
            'api_token_prefix' => '',
            'is_active' => true,
        ]);

        // api_token_hash tekil (unique) olduğundan boş bırakılamaz (birden fazla jetonsuz
        // cihaz çakışır); oluştururken rastgele bir jeton atanır, düz metin gösterilmez.
        // Yönetici "Jeton üret/yenile" ile açıkça kopyalanabilir bir jeton talep eder.
        $device->issueToken();

        Audit::log('device.created', "\"{$device->name}\" cihazını ekledi (#{$device->id}).", $device);

        return $device;
    }

    public function update(Device $device, array $data): void
    {
        $device->fill([
            'name' => $data['name'] ?? $device->name,
            'kind' => $data['kind'] ?? $device->kind,
            'location' => $data['location'] ?? $device->location,
            'direction' => $data['direction'] ?? $device->direction,
            'serial_no' => $data['serial_no'] ?? $device->serial_no,
            'is_active' => $data['is_active'] ?? $device->is_active,
        ])->save();

        Audit::log('device.updated', "\"{$device->name}\" cihazını güncelledi (#{$device->id}).", $device, Audit::diff($device));
    }

    public function delete(Device $device): void
    {
        Audit::log('device.deleted', "\"{$device->name}\" cihazını sildi (#{$device->id}).", $device);
        $device->delete();
    }

    /** Yeni jeton üretir; düz metin yalnız bu çağrının yanıtında döner. */
    public function issueToken(Device $device): string
    {
        $token = $device->issueToken();
        Audit::log('device.token_issued', "\"{$device->name}\" cihazı için yeni erişim jetonu üretti (#{$device->id}).", $device);

        return $token;
    }

    public function upsertIdentity(array $data): DeviceIdentity
    {
        $branchId = app(BranchContext::class)->require();
        $student = Student::query()->findOrFail($data['student_id']);

        $identity = DeviceIdentity::query()->updateOrCreate(
            ['branch_id' => $branchId, 'kind' => $data['kind'], 'identifier' => $data['identifier']],
            ['person_type' => 'student', 'person_id' => $student->id, 'is_active' => $data['is_active'] ?? true],
        );

        Audit::log('device_identity.upserted', "{$student->full_name} için {$data['kind']} eşlemesi kaydetti.", $student);

        return $identity;
    }

    public function deleteIdentity(DeviceIdentity $identity): void
    {
        Audit::log('device_identity.deleted', 'Bir cihaz kimlik eşlemesini sildi (#'.$identity->id.').', $identity);
        $identity->delete();
    }

    /**
     * CSV toplu içe aktarma: sütunlar student_no,kind,identifier[,is_active].
     * kind: fingerprint | card | qr
     *
     * @return array{imported:int, skipped:int, errors:list<string>}
     */
    public function importIdentities(string $csvPath): array
    {
        $branchId = app(BranchContext::class)->require();
        $handle = fopen($csvPath, 'r');
        if (! $handle) {
            throw new BusinessRuleException('CSV dosyası okunamadı.');
        }

        $header = fgetcsv($handle);
        $header = $header ? array_map(fn ($h) => strtolower(trim((string) $h)), $header) : [];
        $idx = array_flip($header);

        if (! isset($idx['student_no'], $idx['kind'], $idx['identifier'])) {
            fclose($handle);
            throw new BusinessRuleException('CSV başlığı student_no, kind, identifier sütunlarını içermeli.');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $line = 1;

        DB::transaction(function () use ($handle, $idx, $branchId, &$imported, &$skipped, &$errors, &$line) {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                    continue;
                }

                $studentNo = trim((string) ($row[$idx['student_no']] ?? ''));
                $kind = strtolower(trim((string) ($row[$idx['kind']] ?? '')));
                $identifier = trim((string) ($row[$idx['identifier']] ?? ''));
                $isActive = isset($idx['is_active']) ? ! in_array(strtolower((string) ($row[$idx['is_active']] ?? '1')), ['0', 'false', 'hayır', 'pasif'], true) : true;

                if ($studentNo === '' || $identifier === '' || ! in_array($kind, ['fingerprint', 'card', 'qr'], true)) {
                    $errors[] = "Satır {$line}: eksik/geçersiz veri.";
                    $skipped++;
                    continue;
                }

                $student = Student::query()->where('student_no', $studentNo)->first();
                if (! $student) {
                    $errors[] = "Satır {$line}: öğrenci no \"{$studentNo}\" bulunamadı.";
                    $skipped++;
                    continue;
                }

                DeviceIdentity::query()->updateOrCreate(
                    ['branch_id' => $branchId, 'kind' => $kind, 'identifier' => $identifier],
                    ['person_type' => 'student', 'person_id' => $student->id, 'is_active' => $isActive],
                );
                $imported++;
            }
        });

        fclose($handle);
        Audit::log('device_identity.imported', "{$imported} kimlik eşlemesini CSV ile içe aktardı.");

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 20)];
    }
}
