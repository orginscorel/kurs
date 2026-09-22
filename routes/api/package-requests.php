<?php

use App\Http\Controllers\Api\Finance\PackageRequestController;
use Illuminate\Support\Facades\Route;

/*
| Portaldan gelen paket / koçluk taleplerinin yönetimi. routes/api.php'de 'staff' ara katmanıyla
| sarılır; her uç ayrıca package_requests.manage yetkisiyle korunur. Şube kapsamı model tarafından
| (BelongsToBranch) otomatiktir.
*/
Route::middleware('permission:package_requests.manage')->group(function () {
    Route::get('package-requests', [PackageRequestController::class, 'index']);
    Route::get('package-requests/coaches', [PackageRequestController::class, 'coaches']);

    Route::middleware('throttle:writes')->group(function () {
        Route::post('package-requests/{packageRequest}/approve', [PackageRequestController::class, 'approve'])->whereNumber('packageRequest');
        Route::post('package-requests/{packageRequest}/reject', [PackageRequestController::class, 'reject'])->whereNumber('packageRequest');
    });
});
