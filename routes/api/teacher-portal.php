<?php

use App\Http\Controllers\Api\TeacherPortal\TeacherPortalController as P;
use App\Http\Controllers\Api\TeacherPortal\TeacherWorkController as W;
use Illuminate\Support\Facades\Route;

/*
| Öğretmen portalı — YALNIZ oturumdaki öğretmenin kendi dersleri, sınıfları ve öğrencileri.
| Öğretmen id'si hiçbir uçta istemciden alınmaz ('portal.teacher' oturumdan çözer); sınıf/öğrenci
| erişimi TeacherScope ile denetlenir. Bu dosya routes/api.php'de 'staff' ara katmanının DIŞINDA yüklenir
| (yalnız-portal öğretmen hesabı yönetim uçlarına giremez).
| Yazma uçları ayrıca teacher_portal.<alan> yetkisi ister; önizlemede (impersonation) yazma zaten kapalıdır.
*/
Route::middleware('portal.teacher')->prefix('teacher-portal')->group(function () {
    Route::get('summary', [P::class, 'summary']);
    Route::get('schedule', [P::class, 'schedule']);
    Route::get('classes', [P::class, 'classes']);
    Route::get('classes/{group}', [P::class, 'classShow'])->whereNumber('group');
    Route::get('classes/{group}/seating', [\App\Http\Controllers\Api\TeacherPortal\TeacherSeatingController::class, 'show'])->whereNumber('group');
    Route::get('students', [P::class, 'students']);
    Route::get('students/{student}', [P::class, 'studentShow'])->whereNumber('student');
    Route::get('observations', [P::class, 'observations']);
    Route::get('requests', [P::class, 'requests']);
    Route::get('study', [P::class, 'study']);
    Route::get('exams', [P::class, 'exams']);
    Route::get('announcements', [P::class, 'announcements']);
    Route::get('profile', [P::class, 'profile']);

    Route::get('attendance', [W::class, 'attendanceIndex']);
    Route::get('attendance/{session}', [W::class, 'attendanceShow'])->whereNumber('session');

    Route::get('homework', [W::class, 'homeworkIndex']);
    Route::get('homework/options', [W::class, 'homeworkOptions']);
    Route::get('homework/{homework}', [W::class, 'homeworkShow'])->whereNumber('homework');
    Route::get('homework/{homework}/documents/{document}', [W::class, 'homeworkDocument'])->whereNumber(['homework', 'document'])->middleware('throttle:60,1');

    Route::middleware('throttle:writes')->group(function () {
        Route::post('announcements/{announcement}/read', [P::class, 'announcementRead'])->whereNumber('announcement');
        Route::post('requests/{contactRequest}/respond', [P::class, 'requestRespond'])->whereNumber('contactRequest');

        Route::middleware('portal.teacher:teacher_portal.observations')->group(function () {
            Route::post('students/{student}/observations', [P::class, 'observationStore'])->whereNumber('student');
            Route::delete('observations/{observation}', [P::class, 'observationDestroy'])->whereNumber('observation');
        });

        Route::post('attendance/{session}', [W::class, 'attendanceStore'])->whereNumber('session')->middleware('portal.teacher:teacher_portal.attendance');

        Route::middleware('portal.teacher:teacher_portal.homework')->group(function () {
            Route::post('homework', [W::class, 'homeworkStore']);
            Route::put('homework/{homework}', [W::class, 'homeworkUpdate'])->whereNumber('homework');
            Route::delete('homework/{homework}', [W::class, 'homeworkDestroy'])->whereNumber('homework');
            Route::put('homework/{homework}/submissions', [W::class, 'homeworkGrade'])->whereNumber('homework');
            Route::post('homework/{homework}/documents', [W::class, 'homeworkUpload'])->whereNumber('homework');
            Route::delete('homework/{homework}/documents/{document}', [W::class, 'homeworkDocumentDestroy'])->whereNumber(['homework', 'document']);
        });
    });
});
