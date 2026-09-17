<?php

namespace App\Services\Discipline;

use App\Models\DisciplineBoardMeeting;
use App\Models\DisciplineDefense;
use App\Services\Finance\FinanceDocuments;
use App\Support\Discipline\DisciplineCatalog as C;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Disiplin belgeleri: savunma istem yazısı, kurul karar tutanağı, disiplin raporu (DomPDF, kurumsal lacivert). */
class DisciplinePdf
{
    public function __construct(private readonly FinanceDocuments $documents) {}

    public function defenseRequest(DisciplineDefense $defense, bool $download = false): Response
    {
        $defense->loadMissing(['incident.subject', 'incident.teacher', 'student.guardians', 'requester']);
        $incident = $defense->incident;
        $student = $defense->student;
        $behaviors = DB::table('discipline_incident_students as p')->join('discipline_behaviors as b', 'b.id', '=', 'p.behavior_id')
            ->where('p.incident_id', $incident->id)->where('p.student_id', $student->id)->pluck('b.name')->implode(', ');

        $pdf = Pdf::loadView('pdf.discipline.defense-request', [
            'institution' => $this->documents->institution(),
            'defense' => $defense, 'incident' => $incident, 'student' => $student, 'behaviors' => $behaviors,
            'className' => $student->currentClassGroups()->pluck('name')->implode(', '),
            'guardianName' => $student->primaryGuardian()?->full_name,
            'requester' => $defense->requester?->name,
        ])->setPaper('a4', 'portrait');
        $name = "savunma-istemi-{$incident->incident_no}-{$student->student_no}.pdf";

        return $download ? $pdf->download($name) : $pdf->stream($name);
    }

    public function boardDecision(DisciplineBoardMeeting $meeting, bool $download = false): Response
    {
        $meeting->loadMissing(['items.incident', 'items.student', 'items.sanction.type']);
        $labels = ['pending' => 'Görüşülmedi', 'accepted' => 'KABUL', 'rejected' => 'RET', 'postponed' => 'Ertelendi'];
        $items = $meeting->items->map(function ($it) use ($labels) {
            $incident = $it->incident;
            $studentIds = $it->student_id ? [$it->student_id] : null;
            $behaviors = DB::table('discipline_incident_students as p')->join('discipline_behaviors as b', 'b.id', '=', 'p.behavior_id')
                ->where('p.incident_id', $incident->id)->where('p.role', 'involved')
                ->when($studentIds, fn ($q) => $q->whereIn('p.student_id', $studentIds))->pluck('b.name')->unique()->implode(', ');
            $s = $it->sanction;

            return [
                'incident_no' => $incident->incident_no, 'occurred' => $incident->occurred_at->format('d.m.Y'),
                'student' => $it->student?->full_name,
                'students' => DB::table('discipline_incident_students as p')->join('students as st', 'st.id', '=', 'p.student_id')
                    ->where('p.incident_id', $incident->id)->where('p.role', 'involved')->pluck('st.full_name')->implode(', '),
                'behaviors' => $behaviors,
                'sanction' => $s?->type?->name,
                'period' => $s?->starts_on ? $s->starts_on->format('d.m.Y').' – '.$s->ends_on->format('d.m.Y')." ({$s->days} gün)" : ($s?->duty_description ?? null),
                'decision' => $it->decision,
                'votes' => $it->result === 'pending' ? '—' : "{$it->votes_for} / {$it->votes_against} / {$it->votes_abstain}",
                'result' => $it->result, 'result_label' => $labels[$it->result] ?? $it->result,
            ];
        })->all();

        $pdf = Pdf::loadView('pdf.discipline.board-decision', [
            'institution' => $this->documents->institution(), 'meeting' => $meeting, 'items' => $items,
            'roles' => C::BOARD_ROLES, 'statusLabel' => C::BOARD_STATUSES[$meeting->status] ?? $meeting->status,
        ])->setPaper('a4', 'portrait');
        $name = "kurul-karari-{$meeting->meeting_no}.pdf";

        return $download ? $pdf->download($name) : $pdf->stream($name);
    }

    public function report(array $report, string $scope): Response
    {
        $pdf = Pdf::loadView('pdf.discipline.report', ['institution' => $this->documents->institution(), 'r' => $report, 'scope' => $scope])
            ->setPaper('a4', 'portrait');

        return $pdf->download("disiplin-raporu-{$report['from']}-{$report['to']}.pdf");
    }
}
