<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsurePortalStudent;
use App\Models\Student;
use App\Services\Accounting\Dec;
use App\Services\Finance\FinanceExtraDocuments;
use App\Services\Finance\StatementService;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal finans ekleri: günlük gecikme uyarısı ve cari hesap ekstresi PDF.
 * Veri yalnız oturumdaki öğrencinin / velinin bağlı çocuklarının (EnsurePortalStudent) kapsamındadır.
 */
class PortalFinanceController extends Controller
{
    /** Vadesi geçmiş ödemeler: veli için tüm çocukların toplamı + çocuk kırılımı. Kurum ayarı kapalıysa enabled=false. */
    public function overdueAlert(Request $request): JsonResponse
    {
        $isGuardian = $request->attributes->get(EnsurePortalStudent::GUARDIAN_ATTRIBUTE) !== null;
        $enabled = (bool) Settings::get($isGuardian ? 'portal.show_guardian_overdue_alert' : 'portal.show_student_overdue_alert', true);
        if (! $enabled) {
            return response()->json(['data' => ['enabled' => false]]);
        }

        /** @var \Illuminate\Support\Collection<int, Student> $children */
        $children = $request->attributes->get(EnsurePortalStudent::CHILDREN_ATTRIBUTE, collect());
        $today = CarbonImmutable::today()->toDateString();
        $rows = DB::table('installments')->whereIn('student_id', $children->pluck('id'))->whereIn('status', ['pending', 'partial', 'overdue'])
            ->groupBy('student_id')
            ->selectRaw('student_id, SUM(due_date < ?) AS oc, COALESCE(SUM(CASE WHEN due_date < ? THEN amount - paid_amount ELSE 0 END), 0) AS oa,
                SUM(due_date = ?) AS tc, COALESCE(SUM(CASE WHEN due_date = ? THEN amount - paid_amount ELSE 0 END), 0) AS ta', [$today, $today, $today, $today])
            ->get()->keyBy('student_id');

        $items = [];
        $count = 0;
        $amount = '0.00';
        $todayCount = 0;
        $todayAmount = '0.00';
        foreach ($children as $c) {
            $r = $rows[$c->id] ?? null;
            $oc = (int) ($r->oc ?? 0);
            $oa = Dec::round(Dec::norm($r->oa ?? 0));
            $count += $oc;
            $amount = bcadd($amount, $oa, 2);
            $todayCount += (int) ($r->tc ?? 0);
            $todayAmount = bcadd($todayAmount, Dec::round(Dec::norm($r->ta ?? 0)), 2);
            if ($oc > 0) {
                $items[] = ['student_id' => $c->id, 'full_name' => $c->full_name, 'count' => $oc, 'amount' => $oa];
            }
        }

        return response()->json(['data' => [
            'enabled' => true,
            'role' => $isGuardian ? 'guardian' : 'student',
            'date' => $today,
            'count' => $count,
            'amount' => $amount,
            'due_today' => ['count' => $todayCount, 'amount' => $todayAmount],
            'children' => $items,
        ]]);
    }

    public function statementPdf(Request $request, StatementService $statements, FinanceExtraDocuments $docs): Response
    {
        /** @var Student $student */
        $student = $request->attributes->get(EnsurePortalStudent::ATTRIBUTE);

        return $docs->statement($statements->build([$student->id]), $statements->holderForStudent($student), null, null, Str::slug($student->full_name), $request->boolean('inline'));
    }
}
