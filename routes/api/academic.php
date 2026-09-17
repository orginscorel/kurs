<?php

use App\Http\Controllers\Api\Academic\AcademicOptionsController;
use App\Http\Controllers\Api\Academic\ClassGroupController;
use App\Http\Controllers\Api\Academic\ClassroomController;
use App\Http\Controllers\Api\Academic\HomeworkController;
use App\Http\Controllers\Api\Academic\ProgramController;
use App\Http\Controllers\Api\Academic\ScheduleController;
use App\Http\Controllers\Api\Academic\StudyController;
use App\Http\Controllers\Api\Academic\SubjectController;
use Illuminate\Support\Facades\Route;

/*
| Akademik modül: program, ders/konu, derslik, sınıf, ders programı, etüt/birebir, ödev.
*/

// Seçenekler: akademik ekranların herhangi birini görebilen herkes
Route::get('academic/options', AcademicOptionsController::class)->middleware('permission:academic.view|schedule.view|study.view|homework.view');

// ---------------------------------------------------------------- programlar / dersler / derslikler / sınıflar
Route::middleware('permission:academic.view')->group(function () {
    Route::get('programs', [ProgramController::class, 'index']);
    Route::get('programs/{program}', [ProgramController::class, 'show']);
    Route::get('subjects', [SubjectController::class, 'index']);
    Route::get('subjects/{subject}', [SubjectController::class, 'show']);
    Route::get('classrooms', [ClassroomController::class, 'index']);
    Route::get('classrooms/{classroom}', [ClassroomController::class, 'show']);
    Route::get('class-groups', [ClassGroupController::class, 'index']);
    Route::get('class-groups/{group}', [ClassGroupController::class, 'show']);
    Route::get('class-groups/{group}/candidates', [ClassGroupController::class, 'candidates']);
});

Route::middleware(['permission:academic.manage', 'throttle:writes'])->group(function () {
    Route::post('programs', [ProgramController::class, 'store']);
    Route::put('programs/{program}', [ProgramController::class, 'update']);
    Route::delete('programs/{program}', [ProgramController::class, 'destroy']);
    Route::put('programs/{program}/subjects', [ProgramController::class, 'syncSubjects']);

    Route::post('subjects', [SubjectController::class, 'store']);
    Route::put('subjects/{subject}', [SubjectController::class, 'update']);
    Route::delete('subjects/{subject}', [SubjectController::class, 'destroy']);
    Route::put('subjects/{subject}/teachers', [SubjectController::class, 'syncTeachers']);
    Route::post('subjects/{subject}/topics', [SubjectController::class, 'storeTopic']);
    Route::put('subjects/{subject}/topics/reorder', [SubjectController::class, 'reorderTopics']);
    Route::put('subjects/{subject}/topics/{topic}', [SubjectController::class, 'updateTopic']);
    Route::delete('subjects/{subject}/topics/{topic}', [SubjectController::class, 'destroyTopic']);

    Route::post('classrooms', [ClassroomController::class, 'store']);
    Route::put('classrooms/{classroom}', [ClassroomController::class, 'update']);
    Route::delete('classrooms/{classroom}', [ClassroomController::class, 'destroy']);

    Route::post('class-groups', [ClassGroupController::class, 'store']);
    Route::put('class-groups/{group}', [ClassGroupController::class, 'update']);
    Route::delete('class-groups/{group}', [ClassGroupController::class, 'destroy']);
    Route::post('class-groups/{group}/students', [ClassGroupController::class, 'addStudents']);
    Route::delete('class-groups/{group}/students/{student}', [ClassGroupController::class, 'removeStudent']);
});

// ---------------------------------------------------------------- ders programı
Route::middleware('permission:schedule.view')->group(function () {
    Route::get('schedule/week', [ScheduleController::class, 'week']);
    Route::get('schedule/sessions', [ScheduleController::class, 'sessions']);
});
Route::middleware(['permission:schedule.manage', 'throttle:writes'])->group(function () {
    Route::post('schedule/check', [ScheduleController::class, 'check']);
    Route::post('schedule', [ScheduleController::class, 'store']);
    Route::put('schedule/{schedule}', [ScheduleController::class, 'update']);
    Route::delete('schedule/{schedule}', [ScheduleController::class, 'destroy']);
    Route::post('schedule/sessions/{session}/cancel', [ScheduleController::class, 'cancelSession']);
    Route::post('schedule/sessions/{session}/restore', [ScheduleController::class, 'restoreSession']);
    Route::post('schedule/sessions/{session}/reassign', [ScheduleController::class, 'reassignSession']);
});
// İşlenen konu notunu dersi veren öğretmen de yazabilir
Route::post('schedule/sessions/{session}/topic', [ScheduleController::class, 'topicSession'])->middleware('permission:schedule.manage|attendance.take');

// ---------------------------------------------------------------- etüt / birebir
Route::middleware('permission:study.view')->group(function () {
    Route::get('study-sessions', [StudyController::class, 'index']);
    Route::get('study-sessions/{study}', [StudyController::class, 'show']);
    Route::post('study-sessions', [StudyController::class, 'store'])->middleware('throttle:writes');
    Route::get('study/teachers/{teacher}/availability', [StudyController::class, 'availability']);
    Route::get('study/teachers/{teacher}/free-slots', [StudyController::class, 'freeSlots']);
});
Route::middleware(['permission:study.manage', 'throttle:writes'])->group(function () {
    Route::put('study-sessions/{study}', [StudyController::class, 'update']);
    Route::post('study-sessions/{study}/approve', [StudyController::class, 'approve']);
    Route::post('study-sessions/{study}/reject', [StudyController::class, 'reject']);
    Route::post('study-sessions/{study}/cancel', [StudyController::class, 'cancel']);
    Route::post('study-sessions/{study}/students', [StudyController::class, 'addStudents']);
    Route::delete('study-sessions/{study}/students/{student}', [StudyController::class, 'removeStudent']);
    Route::post('study-sessions/{study}/attendance', [StudyController::class, 'attendance']);
    Route::put('study/teachers/{teacher}/availability', [StudyController::class, 'setAvailability']);
});

// ---------------------------------------------------------------- ödevler
Route::middleware('permission:homework.view')->group(function () {
    Route::get('homework', [HomeworkController::class, 'index']);
    Route::get('homework/{homework}', [HomeworkController::class, 'show']);
    Route::get('homework/{homework}/documents/{document}/download', [HomeworkController::class, 'download']);
    Route::post('homework/{homework}/submit', [HomeworkController::class, 'submit'])->middleware('throttle:writes');
});
Route::middleware(['permission:homework.manage', 'throttle:writes'])->group(function () {
    Route::post('homework', [HomeworkController::class, 'store']);
    Route::put('homework/{homework}', [HomeworkController::class, 'update']);
    Route::delete('homework/{homework}', [HomeworkController::class, 'destroy']);
    Route::put('homework/{homework}/submissions', [HomeworkController::class, 'grade']);
    Route::post('homework/{homework}/documents', [HomeworkController::class, 'upload']);
    Route::delete('homework/{homework}/documents/{document}', [HomeworkController::class, 'deleteDocument']);
});
