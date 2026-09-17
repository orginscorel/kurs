<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Api\ApiController;
use App\Services\Crm\CrmReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmReportController extends ApiController
{
    public function show(Request $request, CrmReportService $reports): JsonResponse
    {
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        return response()->json(['data' => $reports->report($from, $to)]);
    }
}
