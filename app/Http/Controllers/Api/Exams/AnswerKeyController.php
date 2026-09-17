<?php

namespace App\Http\Controllers\Api\Exams;

use App\Http\Controllers\Api\ApiController;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Services\Exams\ExamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnswerKeyController extends ApiController
{
    use Concerns\TurkishValidation;

    public function __construct(private readonly ExamService $exams) {}

    public function show(Exam $exam): JsonResponse
    {
        $this->exams->ensureSections($exam);
        $exam->load(['sections.questions.topic:id,name,outcome_code,subject_id', 'sections.subject:id,name', 'type:id,code,name']);
        $booklets = $exam->booklets ?? ['A'];

        return response()->json([
            'exam' => ['id' => $exam->id, 'name' => $exam->name, 'status' => $exam->status, 'booklets' => $booklets, 'type' => $exam->type?->code, 'has_results' => $exam->results()->exists()],
            'sections' => $exam->sections->map(function ($s) use ($booklets) {
                $n = (int) $s->question_count;
                $answers = str_repeat(' ', $n);
                $orders = [];
                foreach ($booklets as $b) {
                    if ($b !== 'A') {
                        $orders[$b] = range(1, $n);
                    }
                }
                $topics = [];
                $cancelled = [];
                foreach ($s->questions as $q) {
                    if ($q->number < 1 || $q->number > $n) {
                        continue;
                    }
                    $answers[$q->number - 1] = $q->booklet_map['A']['answer'] ?: ' ';
                    foreach ($orders as $b => $_) {
                        $orders[$b][$q->number - 1] = (int) ($q->booklet_map[$b]['no'] ?? $q->number);
                    }
                    if ($q->topic_id) {
                        $topics[$q->number] = $q->topic_id;
                    }
                    if ($q->is_cancelled) {
                        $cancelled[] = $q->number;
                    }
                }

                return [
                    'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'subject_id' => $s->subject_id, 'subject' => $s->subject?->name,
                    'question_count' => $n, 'answers' => $answers, 'booklet_orders' => (object) $orders, 'topics' => (object) $topics, 'cancelled' => $cancelled,
                    'question_ids' => $s->questions->pluck('id', 'number'),
                ];
            }),
        ]);
    }

    public function save(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.code' => ['required', 'string', 'max:20'],
            'sections.*.answers' => ['present', 'string', 'max:200'],
            'sections.*.booklet_orders' => ['nullable', 'array'],
            'sections.*.booklet_orders.*' => ['array'],
            'sections.*.booklet_orders.*.*' => ['integer', 'min:1', 'max:200'],
            'sections.*.topics' => ['nullable', 'array'],
            'sections.*.topics.*' => ['nullable', 'integer'],
        ], $this->messages(), $this->attributes());

        $this->exams->saveAnswerKey($exam, $data['sections']);
        $exam->refresh();

        return $this->ok($exam->status === 'answer_key_ready' ? 'Cevap anahtarı kaydedildi; sınav optik okumaya hazır.' : ($exam->status === 'results_published' ? 'Cevap anahtarı güncellendi; sonuçlar yeniden puanlandı.' : 'Cevap anahtarı kaydedildi (eksik bölümler var).'), ['status' => $exam->status]);
    }

    public function cancel(Request $request, Exam $exam, ExamQuestion $question): JsonResponse
    {
        $data = $request->validate(['cancelled' => ['required', 'boolean']]);
        $this->exams->setCancelled($exam, $question, $data['cancelled']);

        return $this->ok($data['cancelled'] ? 'Soru iptal edildi; sonuçlar yeniden puanlandı.' : 'Soru iptali kaldırıldı; sonuçlar yeniden puanlandı.');
    }

    public function assignTopics(Request $request, Exam $exam): JsonResponse
    {
        $data = $request->validate([
            'section_code' => ['required', 'string'], 'from' => ['required', 'integer', 'min:1'], 'to' => ['required', 'integer', 'min:1'],
            'topic_id' => ['nullable', 'integer'],
        ], $this->messages(), $this->attributes());
        $n = $this->exams->assignTopics($exam, $data['section_code'], $data['from'], $data['to'], $data['topic_id'] ?? null);

        return $this->ok("{$n} soruya konu atandı.");
    }
}
