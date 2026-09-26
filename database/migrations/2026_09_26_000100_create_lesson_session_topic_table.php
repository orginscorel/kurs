<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ders oturumu ↔ konu ÇOKLU bağı. Önceden tek `lesson_sessions.topic_id` vardı; bir derste birden
 * fazla konu işlenebildiği için pivot eklenir. Geriye dönük: mevcut tekil topic_id'ler pivota taşınır
 * (topic_id sütunu birincil/legacy olarak kalır; yeni akış pivotu kullanır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_session_topic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['lesson_session_id', 'topic_id']);
        });

        // Mevcut tekil konuları pivota taşı (veri kaybı olmadan çoklu akışa geçiş)
        if (Schema::hasColumn('lesson_sessions', 'topic_id')) {
            DB::table('lesson_sessions')
                ->whereNotNull('topic_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $now = now();
                    $insert = [];
                    foreach ($rows as $r) {
                        $insert[] = [
                            'lesson_session_id' => $r->id,
                            'topic_id' => $r->topic_id,
                            'sort' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    if ($insert) {
                        DB::table('lesson_session_topic')->insertOrIgnore($insert);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_session_topic');
    }
};
