<?php

namespace App\Services\Guidance;

use App\Models\Student;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rehberlik raporu PDF: öğrenci bazında görüşme özeti + hedef/gerçekleşen karşılaştırması.
 * KVKK: gizli notlar (private_note) rapora dahil edilmez.
 */
class GuidancePdf
{
    public function __construct(private readonly GoalService $goals) {}

    public function studentReport(Student $student, bool $inline = true): Response
    {
        $student->loadMissing(['guidanceMeetings' => fn ($q) => $q->orderByDesc('met_at')->limit(30), 'goals']);

        $goals = $student->goals->map(fn ($g) => ['goal' => $g, 'progress' => $this->goals->progress($g)]);

        $pdf = Pdf::loadView('pdf.guidance.report', [
            'institution' => $this->institution(),
            'student' => $student,
            'meetings' => $student->guidanceMeetings,
            'goals' => $goals,
            'generatedAt' => now(),
        ])->setPaper('a4', 'portrait');

        $name = 'rehberlik-raporu-'.$student->student_no.'.pdf';

        return $inline ? $pdf->stream($name) : $pdf->download($name);
    }

    private function institution(): array
    {
        $inst = Settings::group('institution');
        $inst['logo_data'] = null;
        if (! empty($inst['logo_path']) && Storage::disk('public')->exists($inst['logo_path'])) {
            $mime = Storage::disk('public')->mimeType($inst['logo_path']) ?: 'image/png';
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $inst['logo_data'] = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($inst['logo_path']));
            }
        }

        return $inst;
    }
}
