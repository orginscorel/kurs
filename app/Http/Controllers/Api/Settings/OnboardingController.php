<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Api\ApiController;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Classroom;
use App\Models\EducationPackage;
use App\Models\FinanceAccount;
use App\Models\Guardian;
use App\Models\Integration;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TimeTemplate;
use App\Support\BranchContext;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Kurulum sihirbazı özeti: her adımın tamamlanma durumu arka uç sayımlarından hesaplanır.
 */
class OnboardingController extends ApiController
{
    public function overview(): JsonResponse
    {
        $branchId = app(BranchContext::class)->require();
        $institution = Settings::group('institution');
        $current = AcademicTerm::query()->where('is_current', true)->first();

        $programs = Program::query()->where('is_active', true)->get(['id', 'name', 'code']);
        $programHours = DB::table('program_subject')->whereIn('program_id', $programs->pluck('id'))
            ->groupBy('program_id')->selectRaw('program_id, COUNT(*) AS subjects, COALESCE(SUM(weekly_hours), 0) AS hours')->get()->keyBy('program_id');

        $classGroups = ClassGroup::query()->where('is_active', true)->when($current, fn ($q) => $q->where('academic_term_id', $current->id));
        $whatsapp = Integration::query()->where('branch_id', $branchId)->where('kind', 'whatsapp')->first();

        $counts = [
            'terms' => AcademicTerm::query()->count(),
            'subjects' => Subject::query()->where('is_active', true)->count(),
            'programs' => $programs->count(),
            'programs_with_subjects' => $programHours->count(),
            'classrooms' => Classroom::query()->where('is_active', true)->count(),
            'time_templates' => TimeTemplate::query()->where('is_active', true)->count(),
            'class_groups' => (clone $classGroups)->count(),
            'packages' => EducationPackage::query()->where('is_active', true)->count(),
            'accounts' => FinanceAccount::query()->where('is_active', true)->count(),
            'teachers' => Teacher::query()->where('is_active', true)->count(),
            'teacher_users' => Teacher::query()->where('is_active', true)->whereNotNull('user_id')->count(),
            'students' => Student::query()->whereIn('status', ['active', 'enrolled', 'pending', 'frozen'])->count(),
            'guardians' => Guardian::query()->count(),
        ];
        $structureSaved = DB::table('settings')->where('group', 'classes')->where('key', 'structure')
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))->exists();

        $steps = [
            'kurum' => filled($institution['name'] ?? null) && (filled($institution['phone'] ?? null) || filled($institution['address'] ?? null)),
            'logo' => filled($institution['logo_path'] ?? null),
            'donem' => $current !== null,
            'dersler' => $counts['subjects'] > 0,
            'programlar' => $counts['programs'] > 0 && $counts['programs_with_subjects'] > 0,
            'derslikler' => $counts['classrooms'] > 0,
            'zaman' => $counts['time_templates'] > 0,
            'siniflar' => $counts['class_groups'] > 0,
            'paketler' => $counts['packages'] > 0,
            'ogretmenler' => $counts['teachers'] > 0,
            'whatsapp' => $whatsapp !== null && $whatsapp->is_enabled && $whatsapp->status === 'connected',
            'ogrenciler' => $counts['students'] > 0,
        ];

        return response()->json([
            'institution' => [
                'name' => $institution['name'] ?? null, 'short_name' => $institution['short_name'] ?? null,
                'onboarding_completed' => (bool) ($institution['onboarding_completed'] ?? false),
                'logo_url' => filled($institution['logo_path'] ?? null) ? Storage::disk('public')->url($institution['logo_path']) : null,
            ],
            'current_term' => $current?->only(['id', 'name', 'starts_on', 'ends_on']),
            'counts' => $counts,
            'steps' => $steps,
            'done' => count(array_filter($steps)),
            'total' => count($steps),
            'class_structure_saved' => $structureSaved,
            'programs' => $programs->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'code' => $p->code,
                'subjects' => (int) ($programHours[$p->id]->subjects ?? 0), 'weekly_hours' => (int) ($programHours[$p->id]->hours ?? 0),
            ])->values(),
            'class_groups' => (clone $classGroups)->orderBy('name')->limit(60)->get(['id', 'name', 'capacity'])->map->only(['id', 'name', 'capacity'])->values(),
            'whatsapp' => $whatsapp ? ['provider' => $whatsapp->provider, 'status' => $whatsapp->status, 'is_enabled' => (bool) $whatsapp->is_enabled] : null,
        ]);
    }
}
