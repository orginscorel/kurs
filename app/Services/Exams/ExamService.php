<?php

namespace App\Services\Exams;

use App\Exceptions\BusinessRuleException;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamResult;
use App\Models\ExamSection;
use App\Models\ExamType;
use App\Models\Subject;
use App\Models\Topic;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Deneme sınavı yaşam döngüsü: oluşturma (tür şablonundan bölümler), cevap anahtarı,
 * kitapçık eşlemesi, konu atama, soru iptali. Puanlama ExamScoring/ExamResultService'te.
 */
class ExamService
{
    public function __construct(private readonly ExamResultService $results) {}

    /** @param array{exam_type_id:int, name:string, exam_date:string, publisher?:?string, scope?:string, booklets?:list<string>, wrong_penalty_ratio?:float|string|null, base_score?:float|string|null, academic_term_id?:?int, sections?:list<array>} $data */
    public function create(array $data): Exam
    {
        $type = ExamType::query()->findOrFail($data['exam_type_id']);

        return DB::transaction(function () use ($data, $type) {
            $exam = Exam::query()->create([
                'exam_type_id' => $type->id,
                'academic_term_id' => $data['academic_term_id'] ?? DB::table('academic_terms')->where('branch_id', $type->branch_id)->where('is_current', true)->value('id'),
                'name' => $data['name'],
                'publisher' => $data['publisher'] ?? null,
                'scope' => $data['scope'] ?? 'institution',
                'exam_date' => $data['exam_date'],
                'wrong_penalty_ratio' => $data['wrong_penalty_ratio'] ?? $type->wrong_penalty_ratio,
                'base_score' => $data['base_score'] ?? $type->base_score,
                'booklets' => $this->normalizeBooklets($data['booklets'] ?? ['A', 'B']),
                'status' => 'draft',
                'created_by' => Auth::id(),
            ]);

            $this->writeSections($exam, $data['sections'] ?? $this->sectionsFromType($type));
            Audit::log('exam.created', "{$exam->name} denemesini oluşturdu.", $exam);

            return $exam;
        });
    }

    public function update(Exam $exam, array $data): Exam
    {
        return DB::transaction(function () use ($exam, $data) {
            $exam->fill(array_intersect_key($data, array_flip(['name', 'publisher', 'scope', 'exam_date', 'academic_term_id', 'wrong_penalty_ratio', 'base_score'])));
            if (isset($data['booklets'])) {
                $exam->booklets = $this->normalizeBooklets($data['booklets']);
            }
            $penaltyChanged = $exam->isDirty('wrong_penalty_ratio') || $exam->isDirty('base_score');
            $exam->save();
            $diff = Audit::diff($exam);

            if (isset($data['sections'])) {
                $this->writeSections($exam, $data['sections']);
                $penaltyChanged = true;
            }

            if ($penaltyChanged && $exam->results()->exists()) {
                $this->results->rescore($exam->fresh(['sections.questions']));
                $this->results->refreshAnalytics($exam);
            }

            Audit::log('exam.updated', "{$exam->name} denemesini güncelledi.", $exam, $diff);

            return $exam;
        });
    }

    public function delete(Exam $exam): void
    {
        if ($exam->status === 'results_published') {
            throw new BusinessRuleException('Sonuçları yayımlanmış bir sınav silinemez.', 'exam_published');
        }
        DB::transaction(function () use ($exam) {
            $studentIds = ExamResult::query()->where('exam_id', $exam->id)->pluck('student_id')->all();
            $exam->delete();
            Audit::log('exam.deleted', "{$exam->name} denemesini sildi.", $exam);
            if ($studentIds) {
                $this->results->rebuildStudentTopicStats($studentIds);
            }
        });
    }

    /** Bölümü olmayan (eski taslak) sınava tür şablonundan bölüm üretir. */
    public function ensureSections(Exam $exam): Exam
    {
        if ($exam->sections()->exists()) {
            return $exam;
        }
        $this->writeSections($exam, $this->sectionsFromType($exam->type));

        return $exam->load('sections');
    }

    /** @return list<array{code:string,name:string,subject_code:?string,question_count:int,coefficient:float}> */
    public function sectionsFromType(ExamType $type): array
    {
        return array_map(fn ($s) => [
            'code' => $s['code'], 'name' => $s['name'], 'subject_code' => $s['subject_code'] ?? null,
            'question_count' => (int) $s['question_count'], 'coefficient' => (float) $s['coefficient'],
        ], $type->sections ?? []);
    }

    /**
     * Cevap anahtarını kaydeder.
     *
     * @param list<array{code:string, answers:string, booklet_orders?:array<string, list<int>>, topics?:array<int|string, int|null>}> $sections
     *   answers        A kitapçığı sırasında cevaplar ("ABCDE…", boş = ' ' ya da '-')
     *   booklet_orders {"B":[7,3,…]} kanonik i. sorunun B kitapçığındaki numarası (permütasyon)
     *   topics         {"1": topicId, "2": null …}
     */
    public function saveAnswerKey(Exam $exam, array $sections): Exam
    {
        if ($exam->status === 'results_published' && ! Auth::user()?->can('exams.publish')) {
            throw new BusinessRuleException('Yayımlanmış sınavın cevap anahtarını yalnızca yayımlama yetkisi olan kullanıcı değiştirebilir.', 'exam_published');
        }

        $exam->load('sections.questions');
        $booklets = $exam->booklets ?? ['A'];

        DB::transaction(function () use ($exam, $sections, $booklets) {
            foreach ($sections as $input) {
                $section = $exam->sections->firstWhere('code', $input['code']);
                if (! $section) {
                    throw new BusinessRuleException("Bilinmeyen bölüm: {$input['code']}", 'unknown_section');
                }
                $n = (int) $section->question_count;
                $answers = strtoupper(str_pad(mb_substr($input['answers'] ?? '', 0, $n), $n, ' '));
                $orders = [];
                foreach ($booklets as $b) {
                    if ($b === 'A') {
                        continue;
                    }
                    $order = $input['booklet_orders'][$b] ?? null;
                    if ($order !== null) {
                        $order = array_map('intval', array_values($order));
                        if (count($order) !== $n || array_diff(range(1, $n), $order) !== []) {
                            throw new BusinessRuleException("{$section->name} bölümü için {$b} kitapçığı sıralaması 1..{$n} arasında her numarayı bir kez içermeli.", 'invalid_booklet_order');
                        }
                    }
                    $orders[$b] = $order;
                }
                $existing = $section->questions->keyBy('number');
                $topics = $input['topics'] ?? [];

                for ($q = 1; $q <= $n; $q++) {
                    $answer = $answers[$q - 1];
                    $answer = in_array($answer, ['A', 'B', 'C', 'D', 'E'], true) ? $answer : '';
                    $prev = $existing->get($q);
                    $map = ['A' => ['no' => $q, 'answer' => $answer]];
                    foreach ($booklets as $b) {
                        if ($b === 'A') {
                            continue;
                        }
                        $no = $orders[$b][$q - 1] ?? ($prev?->booklet_map[$b]['no'] ?? $q);
                        $map[$b] = ['no' => (int) $no, 'answer' => $answer];
                    }
                    $topicId = array_key_exists($q, $topics) ? $topics[$q] : (array_key_exists((string) $q, $topics) ? $topics[(string) $q] : ($prev?->topic_id));
                    if ($topicId !== null) {
                        $topicId = (int) $topicId;
                    }

                    ExamQuestion::query()->updateOrCreate(
                        ['exam_section_id' => $section->id, 'number' => $q],
                        ['booklet_map' => $map, 'topic_id' => $topicId ?: null, 'is_cancelled' => $prev?->is_cancelled ?? false],
                    );
                }
                ExamQuestion::query()->where('exam_section_id', $section->id)->where('number', '>', $n)->delete();
            }

            $exam->unsetRelation('sections');
            $exam->load('sections.questions');
            $this->syncKeyStatus($exam);

            if ($exam->results()->exists()) {
                $this->results->rescore($exam);
                $this->results->refreshAnalytics($exam);
            }

            Audit::log('exam.answer_key_saved', "{$exam->name} cevap anahtarını kaydetti.", $exam);
        });

        return $exam;
    }

    /** Toplu konu atama: "1-10 → Problemler" */
    public function assignTopics(Exam $exam, string $sectionCode, int $from, int $to, ?int $topicId): int
    {
        $section = $exam->sections()->where('code', $sectionCode)->firstOrFail();
        if ($topicId !== null) {
            Topic::query()->findOrFail($topicId);
        }
        $count = ExamQuestion::query()->where('exam_section_id', $section->id)->whereBetween('number', [min($from, $to), max($from, $to)])->update(['topic_id' => $topicId]);

        if ($exam->status === 'results_published') {
            $this->results->rebuildStudentTopicStats(ExamResult::query()->where('exam_id', $exam->id)->pluck('student_id')->all());
        }

        return $count;
    }

    /** Soru iptali: iptal edilen soru herkese doğru sayılır; sonuçlar anında yeniden puanlanır. */
    public function setCancelled(Exam $exam, ExamQuestion $question, bool $cancelled): void
    {
        if ($question->section->exam_id !== $exam->id) {
            throw new BusinessRuleException('Soru bu sınava ait değil.', 'question_mismatch');
        }
        DB::transaction(function () use ($exam, $question, $cancelled) {
            $question->forceFill(['is_cancelled' => $cancelled])->save();
            $exam->unsetRelation('sections');
            if ($exam->results()->exists()) {
                $this->results->rescore($exam);
                $this->results->refreshAnalytics($exam);
            }
            Audit::log('exam.question_cancelled', sprintf('%s · %s %d. soruyu %s.', $exam->name, $question->section->name, $question->number, $cancelled ? 'iptal etti' : 'iptalden çıkardı'), $exam);
        });
    }

    /** Tüm sonuçları yeniden puanlar + sıralama ve istatistikleri tazeler. */
    public function recalculate(Exam $exam): int
    {
        $exam->unsetRelation('sections');
        $n = $this->results->rescore($exam);
        $this->results->refreshAnalytics($exam);
        Audit::log('exam.recalculated', "{$exam->name} sonuçlarını yeniden hesapladı ({$n} öğrenci).", $exam);

        return $n;
    }

    /** Anahtar tam mı? Taslak ↔ anahtar hazır durumu (yayımlanmış sınav durumu korunur). */
    public function syncKeyStatus(Exam $exam): void
    {
        if ($exam->status === 'results_published') {
            return;
        }
        $exam->loadMissing('sections.questions');
        $complete = $exam->sections->isNotEmpty() && $exam->sections->every(function (ExamSection $s) {
            $qs = $s->questions;

            return $qs->count() === (int) $s->question_count && $qs->every(fn ($q) => in_array($q->booklet_map['A']['answer'] ?? '', ['A', 'B', 'C', 'D', 'E'], true));
        });
        $status = $complete ? 'answer_key_ready' : 'draft';
        if ($exam->status !== $status) {
            $exam->forceFill(['status' => $status])->save();
        }
    }

    // ------------------------------------------------------------------ yardımcılar

    /** @param list<array{code:string,name:string,subject_code?:?string,subject_id?:?int,question_count:int,coefficient:float|string}> $sections */
    private function writeSections(Exam $exam, array $sections): void
    {
        if ($sections === []) {
            throw new BusinessRuleException('Sınavda en az bir bölüm olmalı.', 'no_sections');
        }
        $subjects = Subject::query()->get(['id', 'code'])->keyBy('code');
        $keep = [];
        foreach (array_values($sections) as $i => $s) {
            $code = strtoupper(trim($s['code']));
            $subjectId = $s['subject_id'] ?? ($subjects[$s['subject_code'] ?? '']->id ?? null);
            $section = ExamSection::query()->updateOrCreate(
                ['exam_id' => $exam->id, 'code' => $code],
                ['name' => $s['name'], 'subject_id' => $subjectId, 'question_count' => (int) $s['question_count'], 'coefficient' => (float) $s['coefficient'], 'sort' => $i],
            );
            $keep[] = $section->id;
        }
        ExamSection::query()->where('exam_id', $exam->id)->whereNotIn('id', $keep)->delete();
        $exam->unsetRelation('sections');
    }

    /** @return list<string> */
    private function normalizeBooklets(array $booklets): array
    {
        $out = array_values(array_unique(array_filter(array_map(fn ($b) => strtoupper(trim((string) $b)), $booklets), fn ($b) => preg_match('/^[A-D]$/', $b))));
        sort($out);
        if (! in_array('A', $out, true)) {
            array_unshift($out, 'A');
        }

        return $out;
    }
}
