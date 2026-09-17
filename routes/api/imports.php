<?php

use App\Http\Controllers\Api\ImportController;
use Illuminate\Support\Facades\Route;

/*
| Excel içe aktarma. Yetki türe göre denetleyicide denetlenir:
| öğrenci → students.create, öğretmen → teachers.manage.
*/
Route::get('imports/{entity}/template', [ImportController::class, 'template'])->whereIn('entity', ['students', 'teachers']);
Route::get('imports/{entity}/history', [ImportController::class, 'index'])->whereIn('entity', ['students', 'teachers']);
Route::post('imports/{entity}/preview', [ImportController::class, 'preview'])->whereIn('entity', ['students', 'teachers'])->middleware('throttle:writes');
Route::post('imports/{job}/commit', [ImportController::class, 'commit'])->whereNumber('job')->middleware('throttle:writes');
Route::delete('imports/{job}', [ImportController::class, 'discard'])->whereNumber('job');
