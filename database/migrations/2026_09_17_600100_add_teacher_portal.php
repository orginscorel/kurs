<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Öğretmen portalı + öğrenci/veli portalı zenginleştirme. Şemaya yalnız EKLEME yapar:
 *  - homework_submissions: answer_text (öğrencinin metin cevabı), graded_at, graded_by
 *  - student_observations: öğretmen gözlem notu / davranış puanı (veliye/öğrenciye açılabilir)
 *  - announcement_reads: duyuru okundu bilgisi (kullanıcı başına)
 *  - contact_requests: veli → öğretmen mesaj / görüşme talebi (gerçek mesaj GÖNDERİLMEZ, kayda düşer)
 * Yetkiler: teachers.credentials / teachers.impersonate (yonetici, mudur) ve teacher_portal.* (ogretmen, rehber, yonetici).
 * 'ogretmen' rolü artık yönetim ekranı yetkisi taşımaz (öğretmen portalına taşındı); down() geri verir.
 */
return new class extends Migration
{
    private const STAFF_PERMISSIONS = ['teachers.credentials', 'teachers.impersonate'];

    private const STAFF_ROLES = ['yonetici', 'mudur'];

    private const PORTAL_PERMISSIONS = ['teacher_portal.access', 'teacher_portal.attendance', 'teacher_portal.homework', 'teacher_portal.observations'];

    private const PORTAL_ROLES = ['ogretmen', 'rehber', 'yonetici'];

    /** Eski 'ogretmen' rolünün yönetim yetkileri (down() için). */
    private const OLD_TEACHER_PERMISSIONS = [
        'search.global', 'students.view', 'academic.view', 'schedule.view', 'attendance.view', 'attendance.take',
        'exams.view', 'homework.view', 'homework.manage', 'study.view', 'study.manage', 'guidance.view',
    ];

    public function up(): void
    {
        Schema::table('homework_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('homework_submissions', 'answer_text')) {
                $table->text('answer_text')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('homework_submissions', 'graded_at')) {
                $table->timestamp('graded_at')->nullable()->after('teacher_note');
            }
            if (! Schema::hasColumn('homework_submissions', 'graded_by')) {
                $table->unsignedBigInteger('graded_by')->nullable()->after('graded_at');
            }
        });

        if (! Schema::hasTable('student_observations')) {
            Schema::create('student_observations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('class_group_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 20)->default('note');          // positive | improve | note
                $table->string('category', 30)->default('general');   // participation, homework, behavior, ...
                $table->tinyInteger('points')->default(0);            // -5 … +5 davranış puanı
                $table->string('body', 1000);
                $table->boolean('visible_to_guardian')->default(false);
                $table->boolean('visible_to_student')->default(false);
                $table->timestamps();
                $table->softDeletes();
                $table->index(['student_id', 'created_at']);
                $table->index(['teacher_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('announcement_reads')) {
            Schema::create('announcement_reads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('read_at');
                $table->unique(['announcement_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('contact_requests')) {
            Schema::create('contact_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();
                $table->foreignId('guardian_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
                $table->string('kind', 20)->default('message');        // message | meeting
                $table->string('subject', 150);
                $table->string('body', 2000);
                $table->string('preferred_times', 200)->nullable();
                $table->string('status', 20)->default('open');         // open | answered | closed
                $table->string('response', 2000)->nullable();
                $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('responded_at')->nullable();
                $table->timestamps();
                $table->index(['teacher_id', 'status']);
                $table->index(['student_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }

        $this->grant(self::STAFF_PERMISSIONS, self::STAFF_ROLES);
        $this->grant(self::PORTAL_PERMISSIONS, self::PORTAL_ROLES);

        // Öğretmen rolü: yönetim uçlarına zaten 'staff' ara katmanı kapalı; yetki listesi de sadeleşir
        $teacherRole = DB::table('roles')->where('guard_name', 'web')->where('name', 'ogretmen')->value('id');
        if ($teacherRole) {
            $old = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', self::OLD_TEACHER_PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->where('role_id', $teacherRole)->whereIn('permission_id', $old)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', [...self::STAFF_PERMISSIONS, ...self::PORTAL_PERMISSIONS])->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            $this->grant(self::OLD_TEACHER_PERMISSIONS, ['ogretmen']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        Schema::dropIfExists('contact_requests');
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('student_observations');

        Schema::table('homework_submissions', function (Blueprint $table) {
            foreach (['answer_text', 'graded_at', 'graded_by'] as $col) {
                if (Schema::hasColumn('homework_submissions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    /** @param list<string> $permissions  @param list<string> $roles */
    private function grant(array $permissions, array $roles): void
    {
        $now = now();
        foreach ($permissions as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        $permissionIds = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $permissions)->pluck('id');
        $roleIds = DB::table('roles')->where('guard_name', 'web')->whereIn('name', $roles)->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
            }
        }
    }
};
