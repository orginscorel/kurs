<?php

use App\Http\Controllers\Api\Finance\AccountController;
use App\Http\Controllers\Api\Finance\CatalogController;
use App\Http\Controllers\Api\Finance\EnrollmentController;
use App\Http\Controllers\Api\Finance\EntryController;
use App\Http\Controllers\Api\Finance\InstallmentController;
use App\Http\Controllers\Api\Finance\PaymentController;
use App\Http\Controllers\Api\Finance\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Finans modülü. Görüntüleme finance.view; her yazma işlemi kendi yetkisiyle.
| Tahsilat / gelir-gider / hesap hareketleri değiştirilemez: düzeltme = iptal (void) + yeni kayıt.
*/

Route::prefix('finance')->group(function () {
    // ---------------------------------------------------------------- görüntüleme
    Route::middleware('permission:finance.view')->group(function () {
        Route::get('overview', [ReportController::class, 'overview']);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payments/export', [PaymentController::class, 'export'])->middleware('permission:reports.export');
        Route::get('payments/{payment}', [PaymentController::class, 'show']);
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt']);

        Route::get('installments', [InstallmentController::class, 'index']);
        Route::get('installments/export', [InstallmentController::class, 'export'])->middleware('permission:reports.export');
        Route::get('receivables/aging', [InstallmentController::class, 'aging']);
        Route::get('receivables/by-student', [InstallmentController::class, 'byStudent']);

        Route::get('enrollments', [EnrollmentController::class, 'index']);
        Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show'])->whereNumber('enrollment');
        Route::get('enrollments/{enrollment}/contract.pdf', [EnrollmentController::class, 'contractPdf'])->whereNumber('enrollment');

        Route::get('entries', [EntryController::class, 'index']);
        Route::get('entries/export', [EntryController::class, 'export'])->middleware('permission:reports.export');
        Route::get('entries/{entry}', [EntryController::class, 'show'])->whereNumber('entry');
        Route::get('categories', [EntryController::class, 'categories']);

        Route::get('accounts', [AccountController::class, 'index']);
        Route::get('accounts/{account}/ledger', [AccountController::class, 'ledger']);
        Route::get('transfers', [AccountController::class, 'transfers']);
        Route::get('day-end', [AccountController::class, 'dayEnd']);

        Route::get('packages', [CatalogController::class, 'packages']);
    });

    Route::get('students/{student}/context', [PaymentController::class, 'studentContext'])->middleware('permission:finance.view|payments.create');
    Route::get('enrollment-options', [EnrollmentController::class, 'options'])->middleware('permission:finance.view|enrollments.create');

    // ---------------------------------------------------------------- tahsilat
    Route::post('payments', [PaymentController::class, 'store'])->middleware(['permission:payments.create', 'throttle:writes']);
    Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->middleware(['permission:payments.void', 'throttle:writes']);

    // ---------------------------------------------------------------- kayıt ve ödeme planı
    Route::post('enrollments/preview-plan', [EnrollmentController::class, 'previewPlan'])->middleware('permission:enrollments.create|installments.manage');
    Route::post('enrollments', [EnrollmentController::class, 'store'])->middleware(['permission:enrollments.create', 'throttle:writes']);
    Route::put('enrollments/{enrollment}/plan', [EnrollmentController::class, 'restructure'])->middleware(['permission:installments.manage', 'throttle:writes']);
    Route::post('enrollments/{enrollment}/price', [EnrollmentController::class, 'adjustPrice'])->middleware(['permission:installments.manage', 'throttle:writes']);
    Route::post('enrollments/{enrollment}/contract', [EnrollmentController::class, 'prepareContract'])->middleware('permission:enrollments.create|installments.manage');
    Route::post('enrollments/{enrollment}/contract/sign', [EnrollmentController::class, 'signContract'])->middleware('permission:enrollments.create|installments.manage');

    // ---------------------------------------------------------------- gelir / gider
    Route::middleware(['permission:expenses.manage', 'throttle:writes'])->group(function () {
        Route::post('entries', [EntryController::class, 'store']);
        Route::post('entries/{entry}/void', [EntryController::class, 'void']);
        Route::post('categories', [EntryController::class, 'storeCategory']);
        Route::put('categories/{category}', [EntryController::class, 'updateCategory']);
        Route::delete('categories/{category}', [EntryController::class, 'destroyCategory']);
    });

    // ---------------------------------------------------------------- kasa / banka / POS
    Route::middleware(['permission:accounts.manage', 'throttle:writes'])->group(function () {
        Route::post('accounts', [AccountController::class, 'store']);
        Route::put('accounts/{account}', [AccountController::class, 'update']);
        Route::delete('accounts/{account}', [AccountController::class, 'destroy']);
        Route::post('accounts/{account}/adjust', [AccountController::class, 'adjust']);
        Route::post('transfers', [AccountController::class, 'transfer']);
        Route::post('transfers/{transfer}/void', [AccountController::class, 'voidTransfer']);
    });

    // ---------------------------------------------------------------- raporlar
    Route::middleware('permission:reports.finance')->group(function () {
        Route::get('reports', [ReportController::class, 'show']);
        Route::get('reports/export', [ReportController::class, 'export'])->middleware('permission:reports.export');
        Route::get('reports/pdf', [ReportController::class, 'pdf'])->middleware('permission:reports.export');
    });

    // ---------------------------------------------------------------- eğitim paketleri (fiyatlandırma)
    Route::middleware(['permission:installments.manage', 'throttle:writes'])->group(function () {
        Route::post('packages', [CatalogController::class, 'storePackage']);
        Route::put('packages/{package}', [CatalogController::class, 'updatePackage']);
        Route::delete('packages/{package}', [CatalogController::class, 'destroyPackage']);
    });

    // ---------------------------------------------------------------- envanter
    Route::middleware('permission:finance.view|inventory.manage')->group(function () {
        Route::get('products', [CatalogController::class, 'products']);
        Route::get('stock-movements', [CatalogController::class, 'movements']);
    });
    Route::middleware(['permission:inventory.manage', 'throttle:writes'])->group(function () {
        Route::post('products', [CatalogController::class, 'storeProduct']);
        Route::put('products/{product}', [CatalogController::class, 'updateProduct']);
        Route::delete('products/{product}', [CatalogController::class, 'destroyProduct']);
        Route::post('products/{product}/stock-in', [CatalogController::class, 'stockIn']);
        Route::post('products/{product}/deliver', [CatalogController::class, 'deliver']);
        Route::post('products/{product}/return', [CatalogController::class, 'returnItem']);
        Route::post('products/{product}/adjust', [CatalogController::class, 'adjustStock']);
    });
});
