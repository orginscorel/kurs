<?php

namespace App\Services\Exams;

use App\Events\ExamResultsPublished;
use App\Exceptions\BusinessRuleException;
use App\Models\ActivityFeed;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamResult;
use App\Models\ExamResultSection;
use App\Models\Student;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Sınav sonucu yazma, sıralama, soru ve kazanım istatistikleri.
 */
class ExamResultService
{
    /**
     * Bir öğrencinin cevaplarını puanlayıp kaydeder (optik veya elle giriş).
     *
     * @param array<string, string> $answersBySection bölüm kodu => kitapçık sırasındaki cevap dizisi
     */
    public function recordAnswers(Exam $exam, Student $student, string $booklet, array $answersBySection, string $source = 'optical', ?int $nationalRank = null): ExamResult
    {
        $booklet = strtoupper($booklet ?: 'A');
        if (! in_array($booklet, $exam->booklets ?? ['A'], true)) {
            throw new BusinessRuleException("Bu sınavda {$booklet} kitapçığı tanımlı değil.", 'invalid_booklet');
        }

        $exam->loadMissing('sections.questions');
        $penalty = (float) $exam->wrong_penalty_ratio;

        return DB::transaction(function () use ($exam, $student, $booklet, $answersBySection, $source, $nationalRank, $penalty) {
            $classGroupId = $student->currentClassGroups()->value('class_groups.id');

            $result = ExamResult::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'student_id' => $student->id],
                ['booklet' => $booklet, 'class_group_id' => $classGroupId, 'source' => $source, 'national_rank' => $nationalRank],
            );

            $totals = ['correct' => 0, 'wrong' => 0, 'blank' => 0, 'net' => 0.0];
            $nets = [];
            $coefficients = [];

            foreach ($exam->sections as $section) {
                $questions = $section->questions->map(fn (ExamQuestion $q) => [
                    'number' => $q->number, 'booklet_map' => $q->booklet_map, 'is_cancelled' => $q->is_cancelled,
                ])->all();

                if ($questions === []) {
                    throw new BusinessRuleException("{$section->name} bölümünün cevap anahtarı girilmemiş.", 'answer_key_missing');
                }

                $canonical = ExamScoring::toCanonical($answersBySection[$section->code] ?? '', $booklet, $questions);
                $scored = ExamScoring::scoreSection($canonical, $questions, $penalty);

                ExamResultSection::query()->updateOrCreate(
                    ['exam_result_id' => $result->id, 'exam_section_id' => $section->id],
                    ['answers' => $canonical] + $scored,
                );

                foreach (['correct', 'wrong', 'blank'] as $k) {
                    $totals[$k] += $scored[$k];
                }
                $totals['net'] += $scored['net'];
                $nets[$section->code] = $scored['net'];
                $coefficients[$section->code] = (float) $section->coefficient;
            }

            $result->forceFill([
                'correct' => $totals['correct'],
                'wrong' => $totals['wrong'],
                'blank' => $totals['blank'],
                'net' => round($totals['net'], 2),
                'score' => ExamScoring::score($nets, $coefficients, (float) $exam->base_score),
            ])->save();

            return $result;
        });
    }

    /** Kurum ve sınıf sıralamasını yeniden hesaplar. */
    public function recalculateRanks(Exam $exam): void
    {
        DB::transaction(function () use ($exam) {
            $results = ExamResult::query()->where('exam_id', $exam->id)->get(['id', 'score', 'net', 'class_group_id']);
            $scoreOf = fn ($r) => (float) ($r->score ?? $r->net);

            $institution = ExamScoring::rank($results->mapWithKeys(fn ($r) => [$r->id => $scoreOf($r)])->all());

            $classRanks = [];
            foreach ($results->groupBy('class_group_id') as $groupId => $group) {
                if ($groupId === '' || $groupId === null) {
                    continue;
                }
                $classRanks += ExamScoring::rank($group->mapWithKeys(fn ($r) => [$r->id => $scoreOf($r)])->all());
            }

            foreach ($results as $r) {
                ExamResult::query()->whereKey($r->id)->update([
                    'institution_rank' => $institution[$r->id] ?? null,
                    'class_rank' => $classRanks[$r->id] ?? null,
                ]);
            }

            $exam->forceFill(['participant_count' => $results->count()])->save();
        });
    }

    /** Soru bazlı dağılım + öğrenci konu başarısı. Sonuç yayımlanırken çalışır. */
    public function buildAnalytics(Exam $exam): void
    {
        $exam->loadMissing('sections.questions');

        DB::transaction(function () use ($exam) {
            foreach ($exam->sections as $section) {
                $rows = ExamResultSection::query()
                    ->where('exam_section_id', $section->id)
                    ->join('exam_results', 'exam_results.id', '=', 'exam_result_sections.exam_result_id')
                    ->get(['exam_result_sections.answers', 'exam_results.student_id']);

                $topicTotals = [];

                foreach ($section->questions as $index => $question) {
                    $key = strtoupper($question->booklet_map['A']['answer'] ?? '');
                    $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
                    $correct = $wrong = $blank = 0;

                    foreach ($rows as $row) {
                        $given = $row->answers[$index] ?? ' ';
                        $isCorrect = $question->is_cancelled || ($given !== ' ' && $given === $key);

                        if ($given === ' ') {
                            $blank++;
                        } else {
                            $dist[$given] = ($dist[$given] ?? 0) + 1;
                            $isCorrect ? $correct++ : $wrong++;
                        }

                        if ($question->topic_id) {
                            $t = &$topicTotals[$row->student_id][$question->topic_id];
                            $t ??= ['asked' => 0, 'correct' => 0, 'wrong' => 0];
                            $t['asked']++;
                            if ($isCorrect) {
                                $t['correct']++;
                            } elseif ($given !== ' ') {
                                $t['wrong']++;
                            }
                            unset($t);
                        }
                    }

                    $wrongChoices = array_diff_key($dist, [$key => true]);
                    arsort($wrongChoices);
                    $mostWrong = array_key_first(array_filter($wrongChoices));

                    DB::table('exam_question_stats')->upsert([[
                        'exam_question_id' => $question->id,
                        'correct_count' => $correct,
                        'wrong_count' => $wrong,
                        'blank_count' => $blank,
                        'choice_distribution' => json_encode($dist),
                        'most_common_wrong' => $mostWrong,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]], ['exam_question_id'], ['correct_count', 'wrong_count', 'blank_count', 'choice_distribution', 'most_common_wrong', 'updated_at']);
                }

                // Kümülatif konu başarısı: bu sınavın katkısı eklenir (yeniden yayımda çift sayım olmaması için
                // sınav ilk kez yayımlanırken çalıştırılır; bkz. publish()).
                foreach ($topicTotals as $studentId => $topics) {
                    foreach ($topics as $topicId => $t) {
                        DB::statement(
                            'INSERT INTO student_topic_stats (student_id, topic_id, asked, correct, wrong, last_exam_at)
                             VALUES (?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE asked = asked + VALUES(asked), correct = correct + VALUES(correct),
                                wrong = wrong + VALUES(wrong), last_exam_at = GREATEST(COALESCE(last_exam_at, VALUES(last_exam_at)), VALUES(last_exam_at))',
                            [$studentId, $topicId, $t['asked'], $t['correct'], $t['wrong'], $exam->exam_date->toDateTimeString()],
                        );
                    }
                }
            }
        });
    }

    /**
     * Kayıtlı (kanonik) cevaplardan tüm sonuçları yeniden puanlar.
     * Cevap anahtarı değiştiğinde ya da soru iptal edildiğinde çağrılır; öğrenci cevapları değişmez.
     *
     * @return int yeniden puanlanan sonuç sayısı
     */
    public function rescore(Exam $exam): int
    {
        $exam->loadMissing('sections.questions');
        $penalty = (float) $exam->wrong_penalty_ratio;
        $questionsBySection = [];
        $coefficients = [];
        foreach ($exam->sections as $section) {
            $questionsBySection[$section->id] = $section->questions->map(fn (ExamQuestion $q) => [
                'number' => $q->number, 'booklet_map' => $q->booklet_map, 'is_cancelled' => $q->is_cancelled,
            ])->all();
            $coefficients[$section->code] = (float) $section->coefficient;
        }

        $count = 0;
        DB::transaction(function () use ($exam, $questionsBySection, $coefficients, $penalty, &$count) {
            ExamResult::query()->where('exam_id', $exam->id)->with('sections')->chunkById(200, function ($results) use ($exam, $questionsBySection, $coefficients, $penalty, &$count) {
                foreach ($results as $result) {
                    $totals = ['correct' => 0, 'wrong' => 0, 'blank' => 0, 'net' => 0.0];
                    $nets = [];
                    foreach ($exam->sections as $section) {
                        $rs = $result->sections->firstWhere('exam_section_id', $section->id);
                        $questions = $questionsBySection[$section->id];
                        if ($questions === []) {
                            continue;
                        }
                        $canonical = str_pad(mb_substr($rs?->answers ?? '', 0, count($questions)), count($questions), ExamScoring::BLANK);
                        $scored = ExamScoring::scoreSection($canonical, $questions, $penalty);
                        ExamResultSection::query()->updateOrCreate(
                            ['exam_result_id' => $result->id, 'exam_section_id' => $section->id],
                            ['answers' => $canonical] + $scored,
                        );
                        foreach (['correct', 'wrong', 'blank'] as $k) {
                            $totals[$k] += $scored[$k];
                        }
                        $totals['net'] += $scored['net'];
                        $nets[$section->code] = $scored['net'];
                    }
                    $result->forceFill([
                        'correct' => $totals['correct'], 'wrong' => $totals['wrong'], 'blank' => $totals['blank'],
                        'net' => round($totals['net'], 2),
                        'score' => ExamScoring::score($nets, $coefficients, (float) $exam->base_score),
                    ])->save();
                    $count++;
                }
            });
        });

        return $count;
    }

    /**
     * Yayımlanmış bir sınavda sonuç eklendi/değişti: sıralama + soru istatistiği + öğrenci konu
     * özeti yeniden üretilir. Konu özeti çift sayım olmasın diye ilgili öğrenciler için tüm yayımlı
     * sınavlardan SIFIRDAN hesaplanır (buildAnalytics'in artımlı davranışı korunur).
     */
    public function refreshAnalytics(Exam $exam): void
    {
        $this->recalculateRanks($exam);
        if ($exam->status !== 'results_published') {
            return;
        }
        $this->rebuildQuestionStats($exam);
        $studentIds = ExamResult::query()->where('exam_id', $exam->id)->pluck('student_id')->all();
        $this->rebuildStudentTopicStats($studentIds);
    }

    /** Soru istatistiklerini (dağılım, en çok seçilen yanlış) yeniden hesaplar; konu özetine dokunmaz. */
    public function rebuildQuestionStats(Exam $exam): void
    {
        $exam->loadMissing('sections.questions');
        DB::transaction(function () use ($exam) {
            foreach ($exam->sections as $section) {
                $answers = ExamResultSection::query()->where('exam_section_id', $section->id)->pluck('answers');
                foreach ($section->questions as $index => $question) {
                    $key = strtoupper($question->booklet_map['A']['answer'] ?? '');
                    $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
                    $correct = $wrong = $blank = 0;
                    foreach ($answers as $string) {
                        $given = $string[$index] ?? ' ';
                        if ($given === ' ') {
                            $blank++;
                            continue;
                        }
                        $dist[$given] = ($dist[$given] ?? 0) + 1;
                        ($question->is_cancelled || $given === $key) ? $correct++ : $wrong++;
                    }
                    $wrongChoices = array_diff_key($dist, [$key => true]);
                    arsort($wrongChoices);
                    $mostWrong = array_key_first(array_filter($wrongChoices));
                    DB::table('exam_question_stats')->upsert([[
                        'exam_question_id' => $question->id, 'correct_count' => $correct, 'wrong_count' => $wrong, 'blank_count' => $blank,
                        'choice_distribution' => json_encode($dist), 'most_common_wrong' => $mostWrong, 'created_at' => now(), 'updated_at' => now(),
                    ]], ['exam_question_id'], ['correct_count', 'wrong_count', 'blank_count', 'choice_distribution', 'most_common_wrong', 'updated_at']);
                }
            }
        });
    }

    /**
     * Verilen öğrencilerin konu özetini tüm yayımlanmış sınavlardan sıfırdan üretir (idempotent).
     * Cevap dizisindeki konum = kanonik soru numarası − 1 (numaralar 1..n ardışıktır).
     *
     * @param list<int> $studentIds
     */
    public function rebuildStudentTopicStats(array $studentIds): void
    {
        if ($studentIds === []) {
            return;
        }
        DB::transaction(function () use ($studentIds) {
            foreach (array_chunk($studentIds, 300) as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                DB::statement("DELETE FROM student_topic_stats WHERE student_id IN ({$in})");
                DB::statement("
                    INSERT INTO student_topic_stats (student_id, topic_id, asked, correct, wrong, last_exam_at)
                    SELECT r.student_id, q.topic_id, COUNT(*) AS asked,
                           SUM(CASE WHEN q.is_cancelled = 1 OR SUBSTRING(rs.answers, q.number, 1) = JSON_UNQUOTE(JSON_EXTRACT(q.booklet_map, '$.A.answer')) THEN 1 ELSE 0 END) AS correct,
                           SUM(CASE WHEN q.is_cancelled = 0 AND SUBSTRING(rs.answers, q.number, 1) NOT IN (' ', '') AND SUBSTRING(rs.answers, q.number, 1) <> JSON_UNQUOTE(JSON_EXTRACT(q.booklet_map, '$.A.answer')) THEN 1 ELSE 0 END) AS wrong,
                           MAX(e.exam_date) AS last_exam_at
                    FROM exam_results r
                    JOIN exams e ON e.id = r.exam_id AND e.status = 'results_published' AND e.deleted_at IS NULL
                    JOIN exam_result_sections rs ON rs.exam_result_id = r.id
                    JOIN exam_questions q ON q.exam_section_id = rs.exam_section_id AND q.topic_id IS NOT NULL
                    WHERE r.student_id IN ({$in})
                    GROUP BY r.student_id, q.topic_id
                ");
            }
        });
    }

    public function publish(Exam $exam): Exam
    {
        if ($exam->status === 'results_published') {
            throw new BusinessRuleException('Sonuçlar zaten yayımlanmış.', 'already_published');
        }
        if (! ExamResult::query()->where('exam_id', $exam->id)->exists()) {
            throw new BusinessRuleException('Yayımlanacak sonuç yok. Önce optik okuma verisini içe aktarın.', 'no_results');
        }

        $this->recalculateRanks($exam);
        $this->buildAnalytics($exam);

        $exam->forceFill(['status' => 'results_published', 'published_at' => now()])->save();

        ActivityFeed::query()->create([
            'kind' => 'exam',
            'message' => "{$exam->name} sonuçları yayımlandı ({$exam->participant_count} öğrenci)",
            'subject_type' => $exam->getMorphClass(),
            'subject_id' => $exam->id,
            'occurred_at' => now(),
        ]);

        Audit::log('exam.published', "{$exam->name} sınav sonuçlarını yayımladı.", $exam);

        DB::afterCommit(fn () => event(new ExamResultsPublished($exam->id)));

        return $exam;
    }
}
