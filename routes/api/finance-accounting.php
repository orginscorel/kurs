<?php

use App\Http\Controllers\Api\Finance\AccountingController;
use App\Http\Controllers\Api\Finance\CollectionController;
use App\Http\Controllers\Api\Finance\DocumentController;
use App\Http\Controllers\Api\Finance\FinanceHubController;
use App\Http\Controllers\Api\Finance\InvoiceController;
use App\Http\Controllers\Api\Finance\ReconciliationController;
use App\Http\Controllers\Api\Finance\RefundController;
use Illuminate\Support\Facades\Route;

/*
| Finans genişletmesi: fatura / e-Arşiv taslağı, muhasebeleşme (yevmiye, mizan, dönem kilidi), iade,
| POS/banka mutabakatı, gecikme takibi, cari ekstre, veli toplu tahsilatı, finans analizleri.
| Görüntüleme finance.view; her yazma kendi yetkisiyle. Belgeler değiştirilmez; düzeltme = iptal/ters kayıt.
*/

Route::prefix('finance')->group(function () {
    // ---------------------------------------------------------------- görüntüleme
    Route::middleware('permission:finance.view')->group(function () {
        Route::get('cockpit', [FinanceHubController::class, 'cockpit']);
        Route::get('overdue-alert', [FinanceHubController::class, 'alert']);
        Route::get('settings', [FinanceHubController::class, 'settings']);

        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/export', [InvoiceController::class, 'export'])->middleware('permission:reports.export');
        Route::get('invoices/options', [InvoiceController::class, 'options']);
        Route::get('invoices/unbilled', [InvoiceController::class, 'unbilled']);
        Route::post('invoices/preview', [InvoiceController::class, 'preview']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('invoice');

        Route::get('refunds', [RefundController::class, 'index']);
        Route::get('refunds/{refund}/pdf', [RefundController::class, 'pdf']);
        Route::get('payments/{payment}/refund-context', [RefundController::class, 'context']);

        Route::get('statements/students/{student}', [FinanceHubController::class, 'studentStatement']);
        Route::get('statements/students/{student}/pdf', [FinanceHubController::class, 'studentStatementPdf']);
        Route::get('statements/guardians/{guardian}', [FinanceHubController::class, 'guardianStatement']);
        Route::get('statements/guardians/{guardian}/pdf', [FinanceHubController::class, 'guardianStatementPdf']);

        Route::get('collections', [CollectionController::class, 'index']);
        Route::get('collections/notes', [CollectionController::class, 'notes']);
        Route::get('collections/staff', [CollectionController::class, 'staff']);
        Route::get('collections/reminder-preview/{student}', [CollectionController::class, 'reminderPreview']);

        Route::get('reconciliation', [ReconciliationController::class, 'overview']);
        Route::get('reconciliation/cards', [ReconciliationController::class, 'pendingCards']);
        Route::get('reconciliation/bank', [ReconciliationController::class, 'bankTransactions']);

        // Belgeler: toplu makbuz / fatura, işlem dekontu
        Route::get('documents/receipts.pdf', [DocumentController::class, 'receipts']);
        Route::get('documents/invoices.pdf', [DocumentController::class, 'invoices']);
        Route::get('documents/vouchers/{type}/{id}.pdf', [DocumentController::class, 'voucher'])->whereNumber('id');
    });

    // ---------------------------------------------------------------- senet (bono)
    Route::middleware('permission:finance.view|installments.manage|enrollments.create')->group(function () {
        Route::post('promissory-notes/preview', [DocumentController::class, 'notesPreview']);
    });
    Route::middleware(['permission:installments.manage|enrollments.create|finance.invoice', 'throttle:writes'])->group(function () {
        Route::post('promissory-notes/prepare', [DocumentController::class, 'notesPrepare']);
        Route::get('promissory-notes/pdf', [DocumentController::class, 'notesPdf']);
    });

    Route::middleware('permission:finance.view|payments.create')->group(function () {
        Route::get('guardians/search', [FinanceHubController::class, 'guardianSearch']);
        Route::get('guardians/{guardian}/context', [FinanceHubController::class, 'guardianContext']);
    });

    // ---------------------------------------------------------------- tahsilat ekleri
    Route::middleware(['permission:payments.create', 'throttle:writes'])->group(function () {
        Route::post('payments/bulk', [FinanceHubController::class, 'bulkCollect']);
        Route::post('payments/{payment}/apply-credit', [FinanceHubController::class, 'applyCredit']);
    });

    // ---------------------------------------------------------------- fatura
    Route::middleware(['permission:finance.invoice', 'throttle:writes'])->group(function () {
        Route::post('invoices', [InvoiceController::class, 'store']);
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->whereNumber('invoice');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->whereNumber('invoice');
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);
        Route::post('invoices/{invoice}/return', [InvoiceController::class, 'createReturn']);
        Route::post('invoices/{invoice}/link-payment', [InvoiceController::class, 'linkPayment']);
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'sendToIntegrator']);
        Route::post('invoices/bulk-drafts', [InvoiceController::class, 'bulkDrafts']);
        Route::post('invoices/from-enrollment/{enrollment}', [InvoiceController::class, 'fromEnrollment']);
    });

    // ---------------------------------------------------------------- iade
    Route::middleware(['permission:finance.refund', 'throttle:writes'])->group(function () {
        Route::post('payments/{payment}/refunds', [RefundController::class, 'store']);
        Route::post('refunds/{refund}/void', [RefundController::class, 'void']);
    });

    // ---------------------------------------------------------------- mutabakat
    Route::middleware(['permission:finance.reconcile', 'throttle:writes'])->group(function () {
        Route::post('reconciliation/pos-settlements', [ReconciliationController::class, 'settle']);
        Route::post('reconciliation/pos-settlements/{settlement}/void', [ReconciliationController::class, 'voidSettlement']);
        Route::post('reconciliation/bank/mark', [ReconciliationController::class, 'mark']);
        Route::post('reconciliation/bank/unmark', [ReconciliationController::class, 'unmark']);
    });

    // ---------------------------------------------------------------- tahsilat takibi
    Route::middleware(['permission:finance.collections', 'throttle:writes'])->group(function () {
        Route::post('collections/notes', [CollectionController::class, 'store']);
        Route::patch('collections/notes/{note}', [CollectionController::class, 'update']);
        Route::post('collections/reminder-drafts', [CollectionController::class, 'reminderDrafts']);
    });

    // ---------------------------------------------------------------- muhasebe
    Route::middleware('permission:finance.accounting')->prefix('accounting')->group(function () {
        Route::get('journal', [AccountingController::class, 'journal']);
        Route::get('journal/export', [AccountingController::class, 'journalExport'])->middleware('permission:reports.export');
        Route::get('journal/{entry}', [AccountingController::class, 'entry'])->whereNumber('entry');
        Route::get('ledger', [AccountingController::class, 'ledger']);
        Route::get('ledger/export', [AccountingController::class, 'ledgerExport'])->middleware('permission:reports.export');
        Route::get('trial-balance', [AccountingController::class, 'trialBalance']);
        Route::get('trial-balance/export', [AccountingController::class, 'trialBalanceExport'])->middleware('permission:reports.export');
        Route::get('chart', [AccountingController::class, 'chart']);
        Route::get('periods', [AccountingController::class, 'periods']);
        Route::get('verify', [AccountingController::class, 'verify']);

        Route::middleware('throttle:writes')->group(function () {
            Route::post('journal', [AccountingController::class, 'storeManual']);
            Route::post('journal/{entry}/reverse', [AccountingController::class, 'reverseManual']);
            Route::post('accounts', [AccountingController::class, 'storeAccount']);
            Route::put('accounts/{account}', [AccountingController::class, 'updateAccount']);
            Route::put('mappings', [AccountingController::class, 'updateMapping']);
        });

        Route::middleware(['permission:finance.period_close', 'throttle:writes'])->group(function () {
            Route::post('periods/close', [AccountingController::class, 'closePeriod']);
            Route::post('periods/reopen', [AccountingController::class, 'reopenPeriod']);
        });
    });

    // ---------------------------------------------------------------- ayarlar
    Route::put('settings', [FinanceHubController::class, 'updateSettings'])->middleware(['permission:settings.manage', 'throttle:writes']);
});

// ---------------------------------------------------------------- Rapor Merkezi: finans analizleri
Route::middleware(['permission:reports.view', 'permission:reports.finance'])->prefix('reports/finance-analytics')->group(function () {
    Route::get('{key}', [FinanceHubController::class, 'analytics'])->where('key', '[a-z-]+');
    Route::get('{key}/export', [FinanceHubController::class, 'analyticsExport'])->where('key', '[a-z-]+')->middleware('permission:reports.export');
    Route::get('{key}/pdf', [FinanceHubController::class, 'analyticsPdf'])->where('key', '[a-z-]+')->middleware('permission:reports.export');
});
