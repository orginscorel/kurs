<?php

namespace App\Http\Controllers\Api\Exams;

use App\Http\Controllers\Api\ApiController;
use App\Models\Exam;
use App\Services\Exams\ExamAnalytics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamAnalysisController extends ApiController
{
    public function __construct(private readonly ExamAnalytics $analytics) {}

    public function questions(Request $request, Exam $exam): JsonResponse
    {
        return response()->json($this->analytics->questionAnalysis($exam, $request->integer('class_group_id') ?: null));
    }

    public function topics(Request $request, Exam $exam): JsonResponse
    {
        return response()->json($this->analytics->topicAnalysis($exam, $request->integer('class_group_id') ?: null));
    }
}
