<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('phone', 20)->index();
            $table->string('guardian_name', 160)->nullable();
            $table->string('guardian_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('school_name', 160)->nullable();
            $table->string('school_grade', 20)->nullable();
            $table->foreignId('interested_program_id')->nullable()->constrained('programs')->nullOnDelete();
            // instagram | google | facebook | whatsapp | website | phone | referral | student_referral | walk_in | other
            $table->string('source', 30);
            $table->string('source_detail', 160)->nullable();
            // new | called | meeting_scheduled | met | offered | undecided | call_again | won | lost
            $table->string('stage', 30)->default('new');
            $table->string('lost_reason', 300)->nullable();
            $table->decimal('offered_price', 12, 2)->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_contacted_at')->nullable();
            $table->dateTime('next_action_at')->nullable();
            $table->string('next_action', 200)->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete(); // kayda dönüşünce
            $table->unsignedInteger('stage_position')->default(0); // kanban içi sıra
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'stage']);
            $table->index(['owner_id', 'next_action_at']);
        });

        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);                      // call | meeting | whatsapp | note | stage_change | offer
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['lead_id', 'created_at']);
        });

        // Görev / hatırlatma (CRM araması, rehberlik takibi, genel iş)
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->nullableMorphs('taskable');              // Lead | Student | Guardian
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at')->nullable();
            $table->string('priority', 10)->default('normal'); // low | normal | high
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['assigned_to', 'completed_at', 'due_at']);
        });

        Schema::create('guidance_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('counselor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('met_at');
            $table->string('kind', 20);                      // individual | guardian | group | phone | online
            $table->text('summary');
            $table->string('goal', 500)->nullable();
            $table->unsignedTinyInteger('motivation')->nullable();   // 1-5
            $table->unsignedTinyInteger('study_discipline')->nullable(); // 1-5
            $table->text('private_note')->nullable();        // yalnız rehberlik yetkisi görür
            $table->string('visibility', 20)->default('staff'); // counselor | staff | guardian
            $table->date('next_meeting_on')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['student_id', 'met_at']);
            $table->index(['branch_id', 'next_meeting_on']);
        });

        Schema::create('student_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('university', 160)->nullable();
            $table->string('department', 160)->nullable();
            $table->unsignedInteger('target_rank')->nullable();
            $table->decimal('target_tyt_net', 6, 2)->nullable();
            $table->decimal('target_ayt_net', 6, 2)->nullable();
            $table->json('subject_targets')->nullable();     // {"MAT": 30, "TUR": 32}
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['student_id', 'is_active']);
        });

        Schema::create('student_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['student_id', 'created_at']);
        });

        // Risk puanı önbelleği (gece yeniden hesaplanır; AI destekli yorum ayrı alanda)
        Schema::create('student_risk_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('score');            // 0-100
            $table->string('level', 10);                     // low | medium | high
            $table->json('factors');                         // [{key,label,value,weight}]
            $table->json('insights')->nullable();            // sistem önerileri (metin)
            $table->timestamp('calculated_at');
            $table->index('level');
        });
    }

    public function down(): void
    {
        foreach (['student_risk_scores', 'student_notes', 'student_goals', 'guidance_meetings', 'tasks', 'lead_activities', 'leads'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
