<?php

namespace App\Http\Controllers\Api\TeacherPortal;

use App\Http\Middleware\EnsurePortalTeacher;
use App\Services\ClassroomDesign\SeatingPlanService;
use App\Services\Teachers\TeacherScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Öğretmen portalı: kendi sınıfının oturma planı — SALT OKUNUR (TeacherScope ile sınıf denetimi). */
class TeacherSeatingController extends Controller
{
    public function show(Request $request, int $group, SeatingPlanService $service): JsonResponse
    {
        /** @var TeacherScope $scope */
        $scope = $request->attributes->get(EnsurePortalTeacher::SCOPE_ATTRIBUTE);
        $scope->assertGroup($group);

        return response()->json([...$service->payload($group, $request->integer('classroom_id') ?: null), 'can_edit' => false, 'can_edit_room' => false]);
    }
}
