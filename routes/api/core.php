<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', [DashboardController::class, 'show'])->middleware('permission:dashboard.view');
Route::get('dashboard/feed', [DashboardController::class, 'feed'])->middleware('permission:dashboard.view|presence.live');
Route::get('operations/today', [OperationsController::class, 'today'])->middleware('permission:operations.view');

Route::get('search', SearchController::class)->middleware('permission:search.global');

Route::post('client-errors', \App\Http\Controllers\Api\ClientErrorController::class)->middleware('throttle:30,1');

Route::get('notifications', [NotificationController::class, 'index']);
Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
Route::post('notifications/read', [NotificationController::class, 'markRead']);
