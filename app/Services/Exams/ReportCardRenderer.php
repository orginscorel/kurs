<?php

namespace App\Services\Exams;

use App\Models\ExamResult;
use App\Services\Exams\Report\Canvas;
use App\Services\Exams\Report\ExamResultCardTemplate;
use App\Services\Exams\Report\ReportTemplate;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Görsel rapor motoru: bir sınav sonucunu markalı PNG karta çevirir (WhatsApp'ta paylaşım için).
 * Çıktı storage/app/private/reports/exam-cards/{exam}/{result}.png altında önbelleklenir;
 * sonuç güncellenince yeniden üretilir. İletişim modülü render() ile dosya yolunu alır.
 */
class ReportCardRenderer
{
    private ReportTemplate $template;

    public function __construct(private readonly ExamAnalytics $analytics)
    {
        $this->template = new ExamResultCardTemplate();
    }

    /** Başka bir rapor şablonu (ör. aylık karne) ile aynı veri boru hattını kullanmak için. */
    public function withTemplate(ReportTemplate $template): static
    {
        $clone = clone $this;
        $clone->template = $template;

        return $clone;
    }

    /** PNG üretir (önbellek varsa onu kullanır) ve mutlak dosya yolunu döner. */
    public function render(ExamResult $result, bool $fresh = false): string
    {
        $disk = Storage::disk('local');
        $relative = "reports/exam-cards/{$result->exam_id}/{$result->id}.png";
        $absolute = $disk->path($relative);

        if (! $fresh && $disk->exists($relative) && $disk->lastModified($relative) >= ($result->updated_at?->getTimestamp() ?? 0)) {
            return $absolute;
        }

        [$w, $h] = $this->template->size();
        $canvas = new Canvas($w, $h);
        $this->template->draw($canvas, $this->data($result));
        $canvas->save($absolute);

        return $absolute;
    }

    /** PNG ikili içeriği (dosyaya yazmadan). */
    public function renderToString(ExamResult $result): string
    {
        [$w, $h] = $this->template->size();
        $canvas = new Canvas($w, $h);
        $this->template->draw($canvas, $this->data($result));

        return $canvas->toPng();
    }

    /** Şablonun beklediği veri paketi; PDF belgesi de aynı paketi kullanır. */
    public function data(ExamResult $result): array
    {
        $result->loadMissing(['exam.type', 'exam.sections', 'student', 'classGroup', 'sections']);
        $exam = $result->exam;
        $institution = Settings::group('institution', $exam->branch_id);
        $logo = $institution['logo_path'] ?? null;
        $logoPath = $logo ? (Storage::disk('public')->exists($logo) ? Storage::disk('public')->path($logo) : (is_file($logo) ? $logo : null)) : null;

        $classTotal = $result->class_group_id ? DB::table('exam_results')->where('exam_id', $exam->id)->where('class_group_id', $result->class_group_id)->count() : 0;
        $sections = $exam->sections->map(function ($s) use ($result) {
            $rs = $result->sections->firstWhere('exam_section_id', $s->id);

            return ['code' => $s->code, 'name' => $s->name, 'question_count' => (int) $s->question_count, 'correct' => (int) ($rs?->correct ?? 0), 'wrong' => (int) ($rs?->wrong ?? 0), 'blank' => (int) ($rs?->blank ?? $s->question_count), 'net' => (float) ($rs?->net ?? 0)];
        })->values()->all();

        $history = $this->analytics->studentHistory($result->student_id, $exam->exam_type_id, 8)
            ->map(fn ($h) => ['label' => \Carbon\Carbon::parse($h->exam_date)->format('d.m'), 'name' => $h->name, 'net' => (float) $h->net, 'exam_id' => $h->id])->all();
        // Henüz yayımlanmamış sınav geçmişte yoksa mevcut sonucu son nokta olarak ekle
        if (! collect($history)->contains('exam_id', $exam->id)) {
            $history[] = ['label' => $exam->exam_date->format('d.m'), 'name' => $exam->name, 'net' => (float) $result->net, 'exam_id' => $exam->id];
        }

        return [
            'institution' => ['name' => $institution['name'] ?? 'Erbaa Bilgi Eğitim', 'short_name' => $institution['short_name'] ?? 'Erbaa Bilgi', 'logo_path' => $logoPath, 'phone' => $institution['phone'] ?? null,
                'footer_line' => \App\Support\InstitutionFormat::footerLine($institution)],
            'student' => ['name' => $result->student->full_name, 'no' => $result->student->student_no, 'class' => $result->classGroup?->name],
            'exam' => ['name' => $exam->name, 'date' => $exam->exam_date->format('d.m.Y'), 'type' => $exam->type?->name ?? '', 'type_code' => $exam->type?->code, 'publisher' => $exam->publisher, 'scope' => $exam->scope, 'booklet' => $result->booklet],
            'sections' => $sections,
            'totals' => ['correct' => (int) $result->correct, 'wrong' => (int) $result->wrong, 'blank' => (int) $result->blank, 'net' => (float) $result->net, 'score' => $result->score !== null ? (float) $result->score : null, 'question_count' => array_sum(array_column($sections, 'question_count'))],
            'ranks' => ['institution' => $result->institution_rank, 'institution_total' => (int) $exam->participant_count ?: DB::table('exam_results')->where('exam_id', $exam->id)->count(), 'class' => $result->class_rank, 'class_total' => $classTotal, 'national' => $result->national_rank],
            'history' => $history,
            'generated_at' => \App\Support\InstitutionFormat::stamp($institution),
        ];
    }
}
