<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Program: TYT, AYT Sayısal, LGS, Birebir… (yönetici ekler)
        Schema::create('programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('exam_track', 20)->nullable();   // TYT | AYT_SAY | AYT_EA | AYT_SOZ | LGS | NONE
            $table->string('kind', 20)->default('group');   // group | private | study
            $table->string('color', 20)->default('indigo');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'code']);
        });

        // Ders (branş): Matematik, Türkçe, Fizik…
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->string('short_name', 20)->nullable();
            $table->string('color', 20)->default('indigo');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->string('name', 160);
            $table->string('outcome_code', 40)->nullable(); // kazanım kodu
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['subject_id', 'parent_id']);
        });

        Schema::create('program_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekly_hours')->default(0);
            $table->text('curriculum')->nullable();
            $table->unique(['program_id', 'subject_id']);
        });

        Schema::create('program_subject_teacher', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->primary(['program_id', 'subject_id', 'teacher_id']);
        });

        Schema::create('teacher_subject', function (Blueprint $table) {
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->primary(['teacher_id', 'subject_id']);
        });

        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);
            $table->string('kind', 20)->default('classroom'); // classroom | study | hall | lab
            $table->unsignedSmallInteger('capacity');
            $table->string('floor', 20)->nullable();
            $table->string('features', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'name']);
        });

        // Sınıf (şube grubu): "12-SAY-A". "class" ayrılmış kelime olduğu için class_groups.
        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('academic_term_id')->constrained();
            $table->foreignId('program_id')->constrained();
            $table->foreignId('homeroom_classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->foreignId('advisor_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('capacity')->default(24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'academic_term_id', 'name']);
        });

        // Öğrencinin sınıf geçmişi: sınıf değişikliği yeni satır, eski satır kapanır.
        Schema::create('class_group_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'left_on']);
            $table->index(['class_group_id', 'left_on']);
        });

        /*
         * Haftalık tekrar eden ders şablonu. Somut ders oturumları (lesson_sessions)
         * bundan üretilir; tek seferlik değişiklik oturum üzerinde yapılır.
         */
        Schema::create('lesson_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('academic_term_id')->constrained();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('classroom_id')->constrained();
            $table->unsignedTinyInteger('weekday');          // 1=Pzt … 7=Paz (ISO)
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'weekday']);
            $table->index(['teacher_id', 'weekday']);
            $table->index(['classroom_id', 'weekday']);
        });

        Schema::create('lesson_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('lesson_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('classroom_id')->constrained();
            $table->date('date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('scheduled'); // scheduled | in_progress | completed | cancelled
            $table->string('cancel_reason', 300)->nullable();
            $table->string('topic_note', 500)->nullable();       // işlenen konu
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('attendance_taken_at')->nullable();
            $table->foreignId('attendance_taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['lesson_schedule_id', 'date']);
            $table->index(['branch_id', 'date']);
            $table->index(['teacher_id', 'starts_at']);
            $table->index(['classroom_id', 'starts_at']);
            $table->index(['class_group_id', 'starts_at']);
        });

        // Etüt ve birebir ders
        Schema::create('study_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('kind', 20)->default('study');   // study (etüt) | private (birebir)
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 200)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('status', 20)->default('requested'); // requested | approved | rejected | completed | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('fee', 12, 2)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->timestamps();
            $table->index(['teacher_id', 'starts_at']);
            $table->index(['branch_id', 'starts_at']);
        });

        Schema::create('study_session_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('attendance', 20)->nullable();    // present | absent | late
            $table->unique(['study_session_id', 'student_id']);
            $table->index('student_id');
        });

        Schema::create('teacher_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->index(['teacher_id', 'weekday']);
        });

        Schema::create('teacher_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('kind', 20)->default('annual');   // annual | sick | excuse | other
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
            $table->index(['teacher_id', 'starts_on']);
        });

        Schema::create('homework', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('teacher_id')->constrained();
            $table->foreignId('subject_id')->constrained();
            $table->foreignId('class_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('assigned_at');
            $table->dateTime('due_at');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'due_at']);
            $table->index(['teacher_id', 'due_at']);
        });

        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('homework_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('assigned'); // assigned | seen | submitted | late | missed
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->string('teacher_note', 1000)->nullable();
            $table->timestamps();
            $table->unique(['homework_id', 'student_id']);
            $table->index(['student_id', 'status']);
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('title', 200);
            $table->text('body');
            // {"all_students":true} | {"class_group_ids":[..]} | {"program_ids":[..]} | {"teachers":true} | {"guardians":true}
            $table->json('audience');
            $table->json('channels');                        // ["app","whatsapp","email","sms"]
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'published_at']);
        });
    }

    public function down(): void
    {
        foreach (['announcements', 'homework_submissions', 'homework', 'teacher_leaves', 'teacher_availabilities', 'study_session_student', 'study_sessions', 'lesson_sessions', 'lesson_schedules', 'class_group_student', 'class_groups', 'classrooms', 'teacher_subject', 'program_subject_teacher', 'program_subject', 'topics', 'subjects', 'programs'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
