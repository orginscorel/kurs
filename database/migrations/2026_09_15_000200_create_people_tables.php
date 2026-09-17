<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * TC kimlik numarası şifreli saklanır. Arama/tekillik için HMAC özeti
         * (national_id_hash) ve ekranda maskeli gösterim için son 4 hane tutulur.
         */
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->text('national_id_encrypted')->nullable();
            $table->char('national_id_hash', 64)->nullable()->index();
            $table->char('national_id_last4', 4)->nullable();
            $table->string('phone', 20)->nullable()->index();
            $table->string('whatsapp_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'last_name', 'first_name']);
        });

        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('title', 60)->nullable();       // Matematik Öğretmeni
            $table->string('specialty', 120)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('color', 20)->default('indigo'); // ders programında görsel ayrım
            $table->date('hired_on')->nullable();
            $table->string('employment_type', 20)->default('full_time'); // full_time | part_time | hourly
            $table->decimal('hourly_rate', 12, 2)->nullable();
            $table->unsignedSmallInteger('max_weekly_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('avatar_path')->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('position', 80);                // Muhasebe, Kayıt Danışmanı…
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->date('hired_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('student_no', 20);
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            // Birleşik ad: hızlı önek araması için tek indeks
            $table->string('full_name', 170)->index();
            $table->text('national_id_encrypted')->nullable();
            $table->char('national_id_hash', 64)->nullable();
            $table->char('national_id_last4', 4)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 10)->nullable();      // female | male | other
            $table->string('school_name', 160)->nullable();
            $table->string('school_grade', 20)->nullable(); // 12, Mezun
            $table->string('field', 20)->nullable();        // SAY | EA | SOZ | DIL | TYT
            $table->string('target_university', 160)->nullable();
            $table->string('target_department', 160)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('whatsapp_phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('photo_path')->nullable();
            /*
             * Yaşam döngüsü: lead → interview → offer → pending → enrolled → active
             * → frozen | withdrawn | graduated. CRM adayı kayda dönünce öğrenci doğar.
             */
            $table->string('status', 20)->default('active');
            $table->foreignId('guidance_teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->date('registered_on')->nullable();
            $table->string('medical_notes', 1000)->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'student_no']);
            $table->unique(['branch_id', 'national_id_hash']);
            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'last_name', 'first_name']);
        });

        Schema::create('guardian_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guardian_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('relationship', 20)->default('parent'); // mother | father | guardian | other
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_financially_responsible')->default(false);
            $table->boolean('receives_notifications')->default(true);
            $table->timestamps();
            $table->unique(['guardian_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        foreach (['guardian_student', 'students', 'employees', 'teachers', 'guardians'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
