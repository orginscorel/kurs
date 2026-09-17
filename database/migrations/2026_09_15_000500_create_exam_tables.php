<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Sınav türü şablonu (TYT, AYT-SAY…): bölümler, soru sayıları, net kuralı
         * (kaç yanlış bir doğruyu götürür) ve puan katsayıları. Deneme oluştururken
         * kopyalanır; sonradan şablon değişse de geçmiş sınavın hesabı bozulmaz.
         */
        Schema::create('exam_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 80);
            $table->decimal('wrong_penalty_ratio', 4, 2)->default(0.25); // 4 yanlış = 1 doğru
            $table->decimal('base_score', 8, 3)->default(100);
            $table->json('sections');                        // [{code,name,subject_code,question_count,coefficient}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('exam_type_id')->constrained();
            $table->foreignId('academic_term_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('publisher', 120)->nullable();    // yayınevi
            $table->string('scope', 20)->default('institution'); // institution | national (Türkiye geneli)
            $table->date('exam_date');
            $table->decimal('wrong_penalty_ratio', 4, 2);
            $table->decimal('base_score', 8, 3);
            $table->json('booklets');                        // ["A","B"]
            $table->string('status', 20)->default('draft');  // draft | answer_key_ready | results_published
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('participant_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['branch_id', 'exam_date']);
        });

        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 20);                      // TUR, MAT, SOS, FEN
            $table->string('name', 80);
            $table->unsignedSmallInteger('question_count');
            $table->decimal('coefficient', 8, 4)->default(1);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->unique(['exam_id', 'code']);
        });

        /*
         * Soru: her kitapçıktaki sıra ve doğru cevap. Kitapçık B'de soru sırası
         * değişebilir; analiz daima A kitapçığının (kanonik) soru numarasıyla yapılır.
         */
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');          // kanonik numara (A)
            $table->json('booklet_map');                     // {"A":{"no":1,"answer":"C"},"B":{"no":7,"answer":"C"}}
            $table->foreignId('topic_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_cancelled')->default(false); // iptal soru herkese doğru sayılır
            $table->unique(['exam_section_id', 'number']);
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('booklet', 2)->default('A');
            $table->unsignedSmallInteger('correct')->default(0);
            $table->unsignedSmallInteger('wrong')->default(0);
            $table->unsignedSmallInteger('blank')->default(0);
            $table->decimal('net', 7, 2)->default(0);
            $table->decimal('score', 8, 3)->nullable();
            $table->unsignedInteger('institution_rank')->nullable();
            $table->unsignedInteger('class_rank')->nullable();
            $table->unsignedInteger('national_rank')->nullable(); // yayınevinden gelen genel sıralama
            $table->string('source', 20)->default('optical');     // optical | manual | import
            $table->timestamps();
            $table->unique(['exam_id', 'student_id']);
            $table->index(['exam_id', 'net']);
            $table->index(['student_id', 'exam_id']);
        });

        // Bölüm (ders) bazında sonuç + öğrencinin cevap dizisi (kanonik sırada)
        Schema::create('exam_result_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_section_id')->constrained()->cascadeOnDelete();
            $table->string('answers', 255);                  // "ABC D E…" boşluk = boş
            $table->unsignedSmallInteger('correct');
            $table->unsignedSmallInteger('wrong');
            $table->unsignedSmallInteger('blank');
            $table->decimal('net', 6, 2);
            $table->unique(['exam_result_id', 'exam_section_id']);
            $table->index(['exam_section_id', 'net']);
        });

        // Soru istatistiği (sonuçlar yayımlanınca hesaplanıp önbelleğe yazılır)
        Schema::create('exam_question_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_question_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('correct_count');
            $table->unsignedInteger('wrong_count');
            $table->unsignedInteger('blank_count');
            $table->json('choice_distribution');             // {"A":12,"B":40,...}
            $table->string('most_common_wrong', 1)->nullable();
            $table->timestamps();
        });

        // Öğrenci-konu başarısı (kazanım analizi için özet)
        Schema::create('student_topic_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('asked')->default(0);
            $table->unsignedInteger('correct')->default(0);
            $table->unsignedInteger('wrong')->default(0);
            $table->timestamp('last_exam_at')->nullable();
            $table->unique(['student_id', 'topic_id']);
        });

        Schema::create('optical_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->string('format', 10);                    // csv | xlsx | txt | json | api
            $table->string('original_name', 255)->nullable();
            $table->string('path', 255)->nullable();
            $table->json('mapping')->nullable();             // sabit genişlikli TXT kolon düzeni / CSV kolon eşleşmesi
            $table->string('status', 20)->default('pending'); // pending | processing | completed | failed
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->json('unmatched')->nullable();           // eşleşmeyen öğrenci no listesi
            $table->string('error', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['optical_imports', 'student_topic_stats', 'exam_question_stats', 'exam_result_sections', 'exam_results', 'exam_questions', 'exam_sections', 'exams', 'exam_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
