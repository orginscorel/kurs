<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ApiController;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditSubjects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request)->with('user:id,name,username')->latest('created_at');

        return $this->paginated($query->paginate($this->perPage($request)), fn (AuditLog $a) => $this->row($a));
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
            'subject_types' => $types = AuditLog::query()->whereNotNull('subject_type')->select('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
            'subject_type_labels' => $types->mapWithKeys(fn ($t) => [$t => AuditSubjects::label($t)]),
            'users' => User::query()->whereHas('roles')->orderBy('name')->get(['id', 'name', 'username']),
        ]);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $auditLog->load('user:id,name,username');

        return response()->json($this->row($auditLog, true));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('user:id,name')->latest('created_at');

        return response()->streamDownload(function () use ($query) {
            $writer = new Writer();
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['Tarih', 'Kullanıcı', 'Eylem', 'Açıklama', 'Konu Türü', 'Konu No', 'IP']));
            $query->chunk(500, function ($chunk) use ($writer) {
                foreach ($chunk as $a) {
                    $writer->addRow(Row::fromValues([
                        $a->created_at?->format('d.m.Y H:i:s'), $a->user?->name ?? 'Sistem', $a->action, $a->description,
                        AuditSubjects::label($a->subject_type) ?? '', $a->subject_id ?? '', $a->ip_address ?? '',
                    ]));
                }
            });
            $writer->close();
        }, 'denetim-kayitlari-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    private function row(AuditLog $a, bool $withChanges = false): array
    {
        $subject = AuditSubjects::resolve($a->subject_type, $a->subject_id, $a->changes);

        return [
            'id' => $a->id, 'created_at' => $a->created_at?->toIso8601String(), 'action' => $a->action, 'description' => $a->description,
            'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'ip_address' => $a->ip_address,
            'subject_label' => AuditSubjects::label($subject['type']), 'subject_url' => AuditSubjects::url($subject['type'], $subject['id']),
            'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'username' => $a->user->username] : null,
            ...($withChanges ? ['changes' => $a->changes, 'user_agent' => $a->user_agent] : []),
        ];
    }

    private function filtered(Request $request): Builder
    {
        $query = AuditLog::query();

        if ($q = trim((string) $request->query('q'))) {
            $query->where('description', 'like', "%{$q}%");
        }
        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }
        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($subjectType = $request->query('subject_type')) {
            $query->where('subject_type', $subjectType);
        }
        if ($subjectId = $request->integer('subject_id')) {
            $query->where('subject_id', $subjectId);
        }
        $this->applyDateRange($query, $request, 'created_at');

        return $query;
    }
}
