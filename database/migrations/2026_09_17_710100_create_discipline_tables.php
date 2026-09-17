<?php

use App\Support\Discipline\DisciplineCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Disiplin ve cezai işlem sistemi. Şemaya yalnız EKLEME yapar:
 *  - discipline_behaviors        davranış kataloğu (olumsuz + olumlu; puan, ciddiyet, önerilen yaptırım)
 *  - discipline_sanction_types   yaptırım kademeleri (kim verir: yetkili / kurul; süreli mi, uzaklaştırma mı)
 *  - discipline_incidents        olay tutanağı (+ discipline_incident_students: olaya karışan/mağdur/tanık öğrenciler)
 *  - discipline_defenses         yazılı savunma istemi ve savunma metni
 *  - discipline_board_meetings   disiplin kurulu toplantısı (+ discipline_board_items: gündem, oy, karar)
 *  - discipline_sanctions        verilen yaptırımlar (süre, düşme tarihi, portal görünürlüğü)
 *  - discipline_appeals          itiraz ve sonucu
 *  - discipline_events           olay zaman çizelgesi
 * Referans veri: varsayılan katalog + kademeler (her şubeye, idempotent), veli bildirim şablonları,
 * PASİF otomasyon kuralı, yetkiler (discipline.*) ve rol atamaları.
 */
return new class extends Migration
{
    private const ALL = ['discipline.view', 'discipline.create', 'discipline.decide', 'discipline.board', 'discipline.settings', 'discipline.export'];

    private const ROLE_GRANTS = [
        'yonetici' => self::ALL,
        'mudur' => self::ALL,
        'rehber' => ['discipline.view', 'discipline.create'],
        'danisman' => ['discipline.view'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('discipline_behaviors')) {
            Schema::create('discipline_behaviors', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained();
                $t->string('code', 40);
                $t->string('name', 120);
                $t->string('category', 30);
                $t->string('kind', 10)->default('negative');        // negative | positive
                $t->unsignedSmallInteger('points')->default(0);     // olumsuzda ceza puanı, olumluda ödül puanı
                $t->string('severity', 10)->default('low');         // low | medium | high | critical
                $t->string('suggested_sanction', 30)->nullable();   // discipline_sanction_types.code
                $t->string('description', 500)->nullable();
                $t->boolean('is_active')->default(true);
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->timestamps();
                $t->unique(['branch_id', 'code']);
                $t->index(['branch_id', 'kind', 'is_active']);
            });
        }

        if (! Schema::hasTable('discipline_sanction_types')) {
            Schema::create('discipline_sanction_types', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained();
                $t->string('code', 30);
                $t->string('name', 80);
                $t->unsignedTinyInteger('level')->default(1);
                $t->string('authority', 10)->default('staff');      // staff (discipline.decide tek başına) | board (kurul kararı)
                $t->boolean('is_suspension')->default(false);       // gün sayısı + tarih aralığı zorunlu, yoklamaya işlenir
                $t->boolean('has_duty')->default(false);            // etüt / hizmet görevi açıklaması
                $t->unsignedSmallInteger('expires_after_days')->nullable(); // süreli: bu kadar gün sonra düşer
                $t->string('tone', 12)->default('warning');
                $t->string('description', 300)->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamps();
                $t->unique(['branch_id', 'code']);
            });
        }

        if (! Schema::hasTable('discipline_incidents')) {
            Schema::create('discipline_incidents', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained();
                $t->string('incident_no', 30)->unique();
                $t->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
                $t->string('kind', 10)->default('negative');        // negative | positive (takdir/teşekkür kaydı)
                $t->dateTime('occurred_at');
                $t->string('location', 120)->nullable();
                $t->foreignId('class_group_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
                $t->string('title', 160)->nullable();
                $t->text('description')->nullable();
                $t->string('witnesses', 500)->nullable();
                $t->string('severity', 10)->default('low');
                $t->string('status', 16)->default('open');          // open | review | decided | appealed | closed
                $t->string('outcome', 16)->nullable();              // unfounded: asılsız → puanlar sayılmaz
                $t->string('source', 20)->default('staff');         // staff | teacher_portal
                $t->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('guardian_notified_at')->nullable();
                $t->string('guardian_notified_via', 20)->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['branch_id', 'status', 'occurred_at']);
                $t->index(['branch_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('discipline_incident_students')) {
            Schema::create('discipline_incident_students', function (Blueprint $t) {
                $t->id();
                $t->foreignId('incident_id')->constrained('discipline_incidents')->cascadeOnDelete();
                $t->foreignId('student_id')->constrained()->cascadeOnDelete();
                $t->foreignId('behavior_id')->nullable()->constrained('discipline_behaviors')->nullOnDelete();
                $t->string('role', 10)->default('involved');        // involved | victim | witness
                $t->unsignedSmallInteger('penalty_points')->default(0);
                $t->unsignedSmallInteger('merit_points')->default(0);
                $t->string('note', 500)->nullable();
                $t->timestamps();
                $t->unique(['incident_id', 'student_id']);
                $t->index(['student_id', 'role']);
            });
        }

        if (! Schema::hasTable('discipline_defenses')) {
            Schema::create('discipline_defenses', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained();
                $t->foreignId('incident_id')->constrained('discipline_incidents')->cascadeOnDelete();
                $t->foreignId('student_id')->constrained()->cascadeOnDelete();
                $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('requested_at');
                $t->date('due_on');
                $t->string('status', 12)->default('requested');     // requested | submitted | waived
                $t->text('request_note')->nullable();
                $t->text('statement')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->string('submitted_via', 12)->nullable();        // staff | portal
                $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['incident_id', 'student_id']);
                $t->index(['branch_id', 'status', 'due_on']);
            });
        }

        if (! Schema::hasTable('discipline_board_meetings')) {
            Schema::create('discipline_board_meetings', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained();
                $t->string('meeting_no', 30)->unique();
                $t->string('title', 160);
                $t->dateTime('scheduled_at');
                $t->string('location', 120)->nullable();
                $t->string('status', 12)->default('planned');       // planned | held | cancelled
                $t->json('members')->nullable();                    // [{user_id, name, role: chair|member|secretary, present}]
                $t->text('notes')->nullable();
                $t->timestamp('held_at')->nullable();
                $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['branch_id', 'status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('discipline_sanctions')) {
            Schema::create('discipline_sanctions', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained();
                $t->string('sanction_no', 30)->unique();
                $t->foreignId('incident_id')->constrained('discipline_incidents')->cascadeOnDelete();
                $t->foreignId('student_id')->constrained()->cascadeOnDelete();
                $t->foreignId('sanction_type_id')->constrained('discipline_sanction_types');
                $t->foreignId('board_meeting_id')->nullable()->constrained('discipline_board_meetings')->nullOnDelete();
                $t->string('status', 12)->default('active');        // proposed | active | completed | expired | appealed | overturned | cancelled
                $t->text('decision_note')->nullable();
                $t->string('duty_description', 300)->nullable();
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->unsignedSmallInteger('days')->nullable();
                $t->date('expires_on')->nullable();
                $t->boolean('visible_to_portal')->default(true);
                $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('decided_at')->nullable();
                $t->timestamp('guardian_notified_at')->nullable();
                $t->string('cancel_reason', 300)->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['branch_id', 'status']);
                $t->index(['student_id', 'status']);
                $t->index(['starts_on', 'ends_on']);
            });
        }

        if (! Schema::hasTable('discipline_board_items')) {
            Schema::create('discipline_board_items', function (Blueprint $t) {
                $t->id();
                $t->foreignId('meeting_id')->constrained('discipline_board_meetings')->cascadeOnDelete();
                $t->foreignId('incident_id')->constrained('discipline_incidents')->cascadeOnDelete();
                $t->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('sanction_id')->nullable()->constrained('discipline_sanctions')->nullOnDelete();
                $t->unsignedSmallInteger('position')->default(0);
                $t->string('result', 12)->default('pending');       // pending | accepted | rejected | postponed
                $t->unsignedTinyInteger('votes_for')->default(0);
                $t->unsignedTinyInteger('votes_against')->default(0);
                $t->unsignedTinyInteger('votes_abstain')->default(0);
                $t->text('decision')->nullable();
                $t->timestamps();
                $t->index(['incident_id']);
            });
        }

        if (! Schema::hasTable('discipline_appeals')) {
            Schema::create('discipline_appeals', function (Blueprint $t) {
                $t->id();
                $t->foreignId('branch_id')->constrained();
                $t->foreignId('sanction_id')->constrained('discipline_sanctions')->cascadeOnDelete();
                $t->foreignId('student_id')->constrained()->cascadeOnDelete();
                $t->string('appellant', 12)->default('guardian');   // student | guardian
                $t->date('appealed_on');
                $t->text('reason');
                $t->string('status', 12)->default('pending');       // pending | accepted | rejected | modified
                $t->text('result_note')->nullable();
                $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('decided_at')->nullable();
                $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['branch_id', 'status']);
            });
        }

        if (! Schema::hasTable('discipline_events')) {
            Schema::create('discipline_events', function (Blueprint $t) {
                $t->id();
                $t->foreignId('incident_id')->constrained('discipline_incidents')->cascadeOnDelete();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->string('type', 30);
                $t->string('message', 500);
                $t->json('meta')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['incident_id', 'id']);
            });
        }

        $this->seedReference();
        $this->grantPermissions();
    }

    private function seedReference(): void
    {
        $now = now();
        foreach (DB::table('branches')->pluck('id') as $branchId) {
            foreach (DisciplineCatalog::defaultSanctionTypes() as $code => $row) {
                DB::table('discipline_sanction_types')->insertOrIgnore($row + ['branch_id' => $branchId, 'code' => $code, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            $i = 0;
            foreach (DisciplineCatalog::defaultBehaviors() as $code => $row) {
                DB::table('discipline_behaviors')->insertOrIgnore($row + ['branch_id' => $branchId, 'code' => $code, 'is_active' => true, 'sort_order' => ++$i, 'created_at' => $now, 'updated_at' => $now]);
            }

            if (Schema::hasTable('message_templates')) {
                foreach (DisciplineCatalog::defaultTemplates() as $key => [$name, $body]) {
                    $exists = DB::table('message_templates')->where('branch_id', $branchId)->where('key', $key)->where('channel', 'whatsapp')->exists();
                    if (! $exists) {
                        DB::table('message_templates')->insert([
                            'branch_id' => $branchId, 'key' => $key, 'channel' => 'whatsapp', 'name' => $name, 'body' => $body,
                            'language' => 'tr', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }

            if (Schema::hasTable('automation_rules')) {
                $name = 'Disiplin yaptırımı kesinleşince veliye bildir';
                $exists = DB::table('automation_rules')->where('branch_id', $branchId)->where('trigger', 'discipline.sanction_decided')->where('name', $name)->exists();
                if (! $exists) {
                    DB::table('automation_rules')->insert([
                        'branch_id' => $branchId, 'name' => $name, 'trigger' => 'discipline.sanction_decided', 'conditions' => null,
                        'actions' => json_encode([['type' => 'whatsapp', 'to' => 'guardian', 'template' => 'discipline.sanction.guardian']], JSON_UNESCAPED_UNICODE),
                        'delay_minutes' => 0, 'is_active' => false, 'run_count' => 0, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function grantPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        $now = now();
        foreach (self::ALL as $name) {
            DB::table('permissions')->insertOrIgnore(['name' => $name, 'guard_name' => 'web', 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (self::ROLE_GRANTS as $role => $perms) {
            $roleId = DB::table('roles')->where('guard_name', 'web')->where('name', $role)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach (DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $perms)->pluck('id') as $pid) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $pid, 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $ids = DB::table('permissions')->whereIn('name', self::ALL)->pluck('id');
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
        if (Schema::hasTable('automation_rules')) {
            DB::table('automation_rules')->where('trigger', 'discipline.sanction_decided')->where('run_count', 0)->delete();
        }
        if (Schema::hasTable('message_templates')) {
            DB::table('message_templates')->whereIn('key', array_keys(DisciplineCatalog::defaultTemplates()))->delete();
        }
        foreach (['discipline_events', 'discipline_appeals', 'discipline_board_items', 'discipline_sanctions', 'discipline_board_meetings',
            'discipline_defenses', 'discipline_incident_students', 'discipline_incidents', 'discipline_sanction_types', 'discipline_behaviors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
