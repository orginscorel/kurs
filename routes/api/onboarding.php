<?php

use App\Http\Controllers\Api\Settings\OnboardingController;
use Illuminate\Support\Facades\Route;

// Kurulum sihirbazı: adım adım ilerleme durumu (arka uç sayımları)
Route::get('onboarding/overview', [OnboardingController::class, 'overview'])->middleware('permission:settings.manage');
