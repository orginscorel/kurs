<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('name', 80);                 // 2026-2027
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->index(['branch_id', 'is_current']);
        });

        // Anahtar-değer ayarları. branch_id NULL = kurum geneli varsayılan.
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('group', 40);
            $table->string('key', 80);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'group', 'key']);
        });

        // Belge/makbuz numaraları için çakışmasız sayaç (satır kilidiyle artar).
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedInteger('year');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->unique(['branch_id', 'name', 'year']);
        });

        /*
         * Denetim kaydı: yalnız ekleme yapılır. Uygulama katmanında güncelleme ve
         * silme yolu yoktur (AuditLog modeli bunları reddeder).
         */
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('action', 60)->index();       // payment.created
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 500);
            $table->json('changes')->nullable();          // {before, after}
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['subject_type', 'subject_id']);
        });

        // Giriş denemeleri: kaba kuvvet tespiti + "cihazlarım" ekranı.
        Schema::create('login_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('username', 120)->nullable();
            $table->boolean('successful');
            $table->string('channel', 20)->default('web'); // web | mobile
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('color', 20)->default('slate');
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->morphs('taggable');
            $table->primary(['tag_id', 'taggable_type', 'taggable_id']);
        });

        // Belgeler: dosyanın kendisi disk/S3'te, yetkili indirme uç noktasıyla sunulur.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->morphs('documentable');
            $table->string('category', 40);              // contract | identity | photo | receipt | permission | other
            $table->string('title');
            $table->string('disk', 20)->default('local');
            $table->string('path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size');
            $table->string('visibility', 20)->default('staff'); // staff | guardian | student
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                   // info | success | warning | critical | payment | academic | attendance | lesson
            $table->string('title');
            $table->string('body', 1000)->nullable();
            $table->string('action_url')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at', 'created_at']);
        });

        // Canlı kurum akışı (dashboard zaman çizelgesi ve SSE yayını).
        Schema::create('activity_feed', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('kind', 30);                   // entry | exit | absent | late | payment | lesson | exam
            $table->string('message', 300);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->index(['branch_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['activity_feed', 'app_notifications', 'documents', 'taggables', 'tags', 'login_events', 'audit_logs', 'sequences', 'settings', 'academic_terms'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
