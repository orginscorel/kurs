<?php

use App\Sync\SyncRegistry;
use App\Sync\SyncSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * KOÇLUK (akademik koçluk) modülü — yalnız EKLEME (additive), SQLite güvenli.
 *  - coaching_assignments : öğrenciye koç ataması (tarihçeli; aktif atama is_active ile bellidir)
 *  - coaching_sessions    : koçluk görüşmeleri (akademik; rehberlikten AYRI)
 *  - coaching_plans        : haftalık çalışma planı (bir öğrenci + hafta başı)
 *  - coaching_plan_items   : plan kalemleri (ders + hedef + yapıldı mı)
 * Ayrıca coaching.view / coaching.manage yetkilerini uygun rollere ekler.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['coaching.view', 'coaching.manage'];

    /** Bu rollere koçluk yetkileri verilir (varsa). yonetici/super-admin zaten tüm yetkileri alır. */
    private const ROLE_PERMS = [
        'mudur' => ['coaching.view', 'coaching.manage'],
        'rehber' => ['coaching.view', 'coaching.manage'],
        'danisman' => ['coaching.view'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('coaching_assignments')) {
            Schema::create('coaching_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('coach_id')->constrained('users')->cascadeOnDelete(); // öğretmen/personel
                $table->dateTime('assigned_at');
                $table->boolean('is_active')->default(true);
                $table->string('note', 300)->nullable();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('uuid')->nullable()->unique();   // eşitleme kimliği (REFERENCE)
                $table->timestamps();
                $table->softDeletes();
                $table->index(['student_id', 'is_active']);
                $table->index(['coach_id', 'is_active']);
                $table->index(['branch_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('coaching_sessions')) {
            Schema::create('coaching_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('held_at');
                $table->text('topics');                                   // konuşulan konular / notlar
                $table->unsignedTinyInteger('focus')->nullable();          // 1-5 odak/çalışma disiplini
                $table->unsignedTinyInteger('motivation')->nullable();     // 1-5 motivasyon
                $table->text('action_items')->nullable();                  // yapılacaklar (metin)
                $table->date('next_session_on')->nullable();
                $table->uuid('uuid')->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['student_id', 'held_at']);
                $table->index(['coach_id', 'held_at']);
                $table->index(['branch_id', 'next_session_on']);
            });
        }

        if (! Schema::hasTable('coaching_plans')) {
            Schema::create('coaching_plans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('coach_id')->nullable()->constrained('users')->nullOnDelete();
                $table->date('week_start');                                // haftanın pazartesisi
                $table->string('note', 500)->nullable();
                $table->uuid('uuid')->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['student_id', 'week_start']);
                $table->index(['branch_id', 'week_start']);
            });
        }

        if (! Schema::hasTable('coaching_plan_items')) {
            Schema::create('coaching_plan_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('coaching_plan_id')->constrained()->cascadeOnDelete();
                $table->string('subject', 120);                            // ders / konu
                $table->string('target_kind', 12)->default('questions');   // questions | hours | topic
                $table->unsignedInteger('target')->nullable();             // hedef soru / saat sayısı
                $table->boolean('is_done')->default(false);
                $table->timestamp('done_at')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->uuid('uuid')->nullable()->unique();
                $table->timestamps();
                $table->index(['coaching_plan_id', 'position']);
            });
        }

        $this->syncPermissions();

        // Yeni eşitlenen tablolar: kayıt defteri + şema önbelleğini tazele (uuid sütunları yukarıda eklendi).
        SyncRegistry::flush();
        app(SyncSchema::class)->flush();
    }

    private function syncPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $now = now();
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }

        $permIds = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->pluck('id', 'name');

        foreach (self::ROLE_PERMS as $role => $perms) {
            $roleId = DB::table('roles')->where('guard_name', 'web')->where('name', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($perms as $perm) {
                if ($pid = $permIds[$perm] ?? null) {
                    DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $roleId]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['coaching_plan_items', 'coaching_plans', 'coaching_sessions', 'coaching_assignments'] as $t) {
            Schema::dropIfExists($t);
        }

        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
