<?php

use App\Http\Controllers\Api\Reports\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Rapor merkezi (/raporlar). Her uç: reports.view + ilgili modül izni (iki ayrı ara katman = VE).
| Dışa aktarma ayrıca reports.export ister. Finans raporu: routes/api/finance.php (reports.finance).
*/
Route::middleware('permission:reports.view')->prefix('reports')->group(function () {
    Route::get('options', [ReportController::class, 'options']);

    Route::middleware('permission:students.view')->group(function () {
        Route::get('students', [ReportController::class, 'students']);
        Route::get('students/export', [ReportController::class, 'studentsExport'])->middleware('permission:reports.export');
    });

    Route::middleware('permission:attendance.view')->group(function () {
        Route::get('attendance', [ReportController::class, 'attendance']);
        Route::get('attendance/export', [ReportController::class, 'attendanceExport'])->middleware('permission:reports.export');
        Route::get('attendance/pdf', [ReportController::class, 'attendancePdf'])->middleware('permission:reports.export');
    });

    Route::middleware([\App\Http\Middleware\JsonFloatPrecision::class, 'permission:exams.view'])->group(function () {
        Route::get('exams', [ReportController::class, 'exams']);
        Route::get('exams/export', [ReportController::class, 'examsExport'])->middleware('permission:reports.export');
    });

    Route::middleware('permission:crm.view')->group(function () {
        Route::get('leads', [ReportController::class, 'leads']);
        Route::get('leads/export', [ReportController::class, 'leadsExport'])->middleware('permission:reports.export');
    });

    // Disiplin: discipline.view; dışa aktarma ayrıca reports.export + discipline.export
    Route::middleware('permission:discipline.view')->group(function () {
        Route::get('discipline', [\App\Http\Controllers\Api\Reports\DisciplineReportController::class, 'show']);
        Route::get('discipline/export', [\App\Http\Controllers\Api\Reports\DisciplineReportController::class, 'export'])->middleware(['permission:reports.export', 'permission:discipline.export']);
        Route::get('discipline/pdf', [\App\Http\Controllers\Api\Reports\DisciplineReportController::class, 'pdf'])->middleware(['permission:reports.export', 'permission:discipline.export']);
    });

    Route::middleware('permission:teachers.view')->group(function () {
        Route::get('teacher-load', [ReportController::class, 'teacherLoad']);
        Route::get('teacher-load/export', [ReportController::class, 'teacherLoadExport'])->middleware('permission:reports.export');
    });
});
