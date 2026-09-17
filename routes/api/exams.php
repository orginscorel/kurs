<?php

use App\Http\Controllers\Api\Exams\AnswerKeyController;
use App\Http\Controllers\Api\Exams\ExamAnalysisController;
use App\Http\Controllers\Api\Exams\ExamController;
use App\Http\Controllers\Api\Exams\ExamResultController;
use App\Http\Controllers\Api\Exams\OpticalImportController;
use Illuminate\Support\Facades\Route;

/*
| Sınav Merkezi: denemeler, cevap anahtarı, optik okuma, sonuçlar, analizler, görsel rapor.
*/

Route::middleware([\App\Http\Middleware\JsonFloatPrecision::class, 'permission:exams.view'])->group(function () {
    Route::get('exams', [ExamController::class, 'index']);
    Route::get('exams/options', [ExamController::class, 'options']);
    Route::get('exams/dashboard', [ExamController::class, 'dashboard']);
    Route::get('exams/topic-analysis', [ExamController::class, 'topics']);
    Route::get('exams/imports', [OpticalImportController::class, 'index']);
    Route::get('exams/optical-layouts', [OpticalImportController::class, 'layouts']);

    Route::get('exams/{exam}', [ExamController::class, 'show']);
    Route::get('exams/{exam}/answer-key', [AnswerKeyController::class, 'show']);
    Route::get('exams/{exam}/results', [ExamResultController::class, 'index']);
    Route::get('exams/{exam}/results/export', [ExamResultController::class, 'export']);
    Route::get('exams/{exam}/results/{result}', [ExamResultController::class, 'show']);
    Route::get('exams/{exam}/results/{result}/pdf', [ExamResultController::class, 'pdf']);
    Route::get('exams/{exam}/results/{result}/card.png', [ExamResultController::class, 'card']);
    Route::get('exams/{exam}/analysis/questions', [ExamAnalysisController::class, 'questions']);
    Route::get('exams/{exam}/analysis/topics', [ExamAnalysisController::class, 'topics']);
    Route::get('exams/{exam}/optical/{import}', [OpticalImportController::class, 'show']);
});

Route::middleware([\App\Http\Middleware\JsonFloatPrecision::class, 'permission:exams.manage'])->group(function () {
    Route::post('exams', [ExamController::class, 'store'])->middleware('throttle:writes');
    Route::put('exams/{exam}', [ExamController::class, 'update']);
    Route::delete('exams/{exam}', [ExamController::class, 'destroy']);
    Route::post('exams/{exam}/recalculate', [ExamController::class, 'recalculate']);
    Route::put('exams/{exam}/answer-key', [AnswerKeyController::class, 'save']);
    Route::post('exams/{exam}/questions/{question}/cancel', [AnswerKeyController::class, 'cancel']);
    Route::post('exams/{exam}/questions/assign-topics', [AnswerKeyController::class, 'assignTopics']);
    Route::delete('exams/{exam}/results/{result}', [ExamResultController::class, 'destroy']);
});

Route::middleware([\App\Http\Middleware\JsonFloatPrecision::class, 'permission:exams.import'])->group(function () {
    Route::post('exams/{exam}/optical', [OpticalImportController::class, 'api']);
    Route::post('exams/{exam}/optical/upload', [OpticalImportController::class, 'upload']);
    Route::get('exams/{exam}/optical/{import}/describe', [OpticalImportController::class, 'describe']);
    Route::post('exams/{exam}/optical/{import}/preview', [OpticalImportController::class, 'preview']);
    Route::post('exams/{exam}/optical/{import}/run', [OpticalImportController::class, 'run']);
    Route::post('exams/{exam}/results/manual', [ExamResultController::class, 'manual']);
    Route::post('exams/optical-layouts', [OpticalImportController::class, 'storeLayout']);
    Route::delete('exams/optical-layouts/{layout}', [OpticalImportController::class, 'destroyLayout']);
});

Route::post('exams/{exam}/publish', [ExamController::class, 'publish'])->middleware([\App\Http\Middleware\JsonFloatPrecision::class, 'permission:exams.publish']);
