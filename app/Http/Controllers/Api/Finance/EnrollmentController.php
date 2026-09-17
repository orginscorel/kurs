<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\AcademicTerm;
use App\Models\ClassGroup;
use App\Models\Contract;
use App\Models\EducationPackage;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Student;
use App\Services\Finance\EnrollmentService;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\InstallmentPlanService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnrollmentController extends FinanceController
{
    public const STATUSES = ['pending' => 'Beklemede', 'active' => 'Aktif', 'frozen' => 'Donduruldu', 'withdrawn' => 'Ayrıldı', 'completed' => 'Tamamlandı'];

    public function index(Request $request): JsonResponse
    {
        $query = Enrollment::query()->select('enrollments.*')
            ->with([
                'student:id,full_name,student_no', 'program:id,name', 'term:id,name', 'contract:id,enrollment_id,contract_no,signed_at',
                'classGroup:id,name', 'package:id,name', 'financialGuardian:id,first_name,last_name,phone',
            ])
            ->addSelect([
                'paid_count' => DB::table('installments')->selectRaw('COUNT(*)')->whereColumn('enrollment_id', 'enrollments.id')->where('status', 'paid'),
                'next_due_date' => DB::table('installments')->selectRaw('MIN(due_date)')->whereColumn('enrollment_id', 'enrollments.id')->whereIn('status', ['pending', 'partial', 'overdue']),
                'paid_total' => DB::table('installments')->selectRaw('COALESCE(SUM(paid_amount), 0)')->whereColumn('enrollment_id', 'enrollments.id')->where('status', '!=', 'cancelled'),
                'plan_total' => DB::table('installments')->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('enrollment_id', 'enrollments.id')->where('status', '!=', 'cancelled'),
                'overdue_total' => DB::table('installments')->selectRaw('COALESCE(SUM(amount - paid_amount), 0)')->whereColumn('enrollment_id', 'enrollments.id')
                    ->whereIn('status', ['pending', 'partial', 'overdue'])->where('due_date', '<', now()->toDateString()),
                'installment_count' => DB::table('installments')->selectRaw('COUNT(*)')->whereColumn('enrollment_id', 'enrollments.id')->where('status', '!=', 'cancelled'),
            ]);

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn (Builder $w) => $w->where('enrollments.enrollment_no', 'like', $q.'%')
                ->orWhereIn('enrollments.student_id', Student::query()->where('full_name', 'like', '%'.$q.'%')->select('id')));
        }
        foreach (['student_id' => 'student_id', 'program_id' => 'program_id', 'term_id' => 'academic_term_id'] as $param => $col) {
            if ($v = $request->integer($param)) {
                $query->where("enrollments.{$col}", $v);
            }
        }
        if (($status = $request->query('status')) && isset(self::STATUSES[$status])) {
            $query->where('enrollments.status', $status);
        }
        if ($request->query('contract') === 'unsigned') {
            $query->whereDoesntHave('contract', fn ($c) => $c->whereNotNull('signed_at'));
        }
        $this->applyDateRange($query, $request, 'enrollments.enrolled_on');
        $this->applySort($query, $request, ['enrolled_on' => 'enrollments.enrolled_on', 'net_price' => 'enrollments.net_price', 'enrollment_no' => 'enrollments.enrollment_no'], '-enrolled_on');

        return $this->paginated($query->paginate($this->perPage($request)), fn (Enrollment $e) => [
            'id' => $e->id, 'enrollment_no' => $e->enrollment_no, 'status' => $e->status, 'status_label' => self::STATUSES[$e->status] ?? $e->status,
            'student' => $e->student ? ['id' => $e->student->id, 'full_name' => $e->student->full_name, 'student_no' => $e->student->student_no] : null,
            'program' => $e->program?->name, 'term' => $e->term?->name, 'enrolled_on' => $e->enrolled_on->toDateString(),
            'net_price' => (string) $e->net_price, 'paid' => $this->dec($e->paid_total), 'remaining' => bcsub($this->dec($e->plan_total), $this->dec($e->paid_total), 2),
            'overdue' => $this->dec($e->overdue_total), 'installment_count' => (int) $e->installment_count,
            'plan_matches' => bccomp($this->dec($e->plan_total), (string) $e->net_price, 2) === 0,
            'contract' => $e->contract ? ['contract_no' => $e->contract->contract_no, 'signed_at' => $e->contract->signed_at?->toAtomString()] : null,
            'class_group' => $e->classGroup?->name, 'package' => $e->package?->name,
            'guardian' => $e->financialGuardian ? ['id' => $e->financialGuardian->id, 'name' => $e->financialGuardian->full_name] : null,
            'paid_count' => (int) $e->paid_count, 'next_due_date' => $e->next_due_date,
        ]);
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        $enrollment->load(['student', 'program', 'term', 'package', 'classGroup', 'financialGuardian', 'installments', 'contract']);
        $allocations = DB::table('payment_allocations as pa')->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->whereIn('pa.installment_id', $enrollment->installments->pluck('id'))
            ->get(['pa.installment_id', 'pa.amount', 'p.id as payment_id', 'p.receipt_no', 'p.paid_at', 'p.voided_at'])->groupBy('installment_id');
        $today = CarbonImmutable::today();

        $installments = $enrollment->installments->map(fn ($i) => [
            'id' => $i->id, 'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(), 'amount' => (string) $i->amount,
            'paid_amount' => (string) $i->paid_amount, 'remaining' => in_array($i->status, ['paid', 'cancelled'], true) ? '0.00' : $i->remaining(),
            'status' => $i->status, 'days_overdue' => in_array($i->status, ['pending', 'partial', 'overdue'], true) ? max(0, (int) $i->due_date->diffInDays($today, false)) : 0,
            'locked' => in_array($i->status, ['paid', 'cancelled'], true),
            'payments' => ($allocations[$i->id] ?? collect())->map(fn ($a) => ['payment_id' => $a->payment_id, 'receipt_no' => $a->receipt_no, 'amount' => $this->dec($a->amount), 'paid_at' => $a->paid_at, 'voided' => $a->voided_at !== null])->values(),
        ]);

        $active = $enrollment->installments->where('status', '!=', 'cancelled');
        $planTotal = $active->reduce(fn ($s, $i) => bcadd($s, (string) $i->amount, 2), '0.00');
        $paid = $active->reduce(fn ($s, $i) => bcadd($s, (string) $i->paid_amount, 2), '0.00');

        $history = DB::table('audit_logs')->where('subject_type', 'enrollment')->where('subject_id', $enrollment->id)
            ->orderByDesc('id')->limit(30)->get(['id', 'action', 'description', 'created_at']);

        $guardians = $enrollment->student?->guardians()->get()->map(fn ($g) => ['id' => $g->id, 'name' => $g->full_name, 'is_financially_responsible' => (bool) $g->pivot->is_financially_responsible]);

        return response()->json(['data' => [
            'id' => $enrollment->id, 'enrollment_no' => $enrollment->enrollment_no, 'status' => $enrollment->status, 'status_label' => self::STATUSES[$enrollment->status] ?? $enrollment->status,
            'student' => $enrollment->student ? ['id' => $enrollment->student->id, 'full_name' => $enrollment->student->full_name, 'student_no' => $enrollment->student->student_no] : null,
            'program' => $enrollment->program?->name, 'term' => $enrollment->term?->name, 'package' => $enrollment->package?->name, 'class_group' => $enrollment->classGroup?->name,
            'financial_guardian' => $enrollment->financialGuardian ? ['id' => $enrollment->financialGuardian->id, 'name' => $enrollment->financialGuardian->full_name] : null,
            'guardians' => $guardians,
            'enrolled_on' => $enrollment->enrolled_on->toDateString(),
            'list_price' => (string) $enrollment->list_price, 'discount_amount' => (string) $enrollment->discount_amount, 'discount_reason' => $enrollment->discount_reason,
            'scholarship_amount' => (string) $enrollment->scholarship_amount, 'scholarship_reason' => $enrollment->scholarship_reason, 'net_price' => (string) $enrollment->net_price,
            'plan_total' => $planTotal, 'paid' => $paid, 'remaining' => bcsub($planTotal, $paid, 2),
            'plan_matches' => bccomp($planTotal, (string) $enrollment->net_price, 2) === 0,
            'installments' => $installments,
            'payments' => Payment::query()->where('enrollment_id', $enrollment->id)->with('account:id,name')->orderByDesc('paid_at')->get()
                ->map(fn ($p) => ['id' => $p->id, 'receipt_no' => $p->receipt_no, 'amount' => (string) $p->amount, 'method' => $p->method, 'method_label' => Payment::METHODS[$p->method] ?? $p->method, 'paid_at' => $p->paid_at->toAtomString(), 'account' => $p->account?->name, 'voided_at' => $p->voided_at?->toAtomString()]),
            'contract' => $enrollment->contract ? [
                'id' => $enrollment->contract->id, 'contract_no' => $enrollment->contract->contract_no,
                'signed_at' => $enrollment->contract->signed_at?->toAtomString(), 'signed_by_name' => $enrollment->contract->signed_by_name,
                'updated_at' => $enrollment->contract->updated_at?->toAtomString(),
            ] : null,
            'history' => $history,
        ]]);
    }

    public function options(): JsonResponse
    {
        return response()->json([
            'terms' => AcademicTerm::query()->orderByDesc('starts_on')->get(['id', 'name', 'starts_on', 'ends_on', 'is_current'])
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'starts_on' => $t->starts_on?->toDateString(), 'ends_on' => $t->ends_on?->toDateString(), 'is_current' => $t->is_current]),
            'programs' => Program::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'packages' => EducationPackage::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'program_id', 'academic_term_id', 'list_price', 'default_installments', 'includes']),
            'class_groups' => ClassGroup::query()->where('is_active', true)->orderBy('name')
                ->withCount(['students as active_students' => fn ($q) => $q->whereNull('class_group_student.left_on')])
                ->get(['id', 'name', 'program_id', 'academic_term_id', 'capacity']),
        ]);
    }

    public function previewPlan(Request $request, EnrollmentService $service): JsonResponse
    {
        $data = $this->validateTr($request, $this->priceRules());
        [$net, $plan] = $this->plan($service, $data);

        return response()->json(['data' => ['net_price' => $net, 'rows' => $plan, 'total' => array_reduce($plan, fn ($s, $r) => bcadd($s, $r['amount'], 2), '0.00')]]);
    }

    public function store(Request $request, EnrollmentService $service, FinanceDocuments $documents): JsonResponse
    {
        $data = $this->validateTr($request, [
            'student_id' => ['required', 'integer'],
            'academic_term_id' => ['required', 'integer'],
            'program_id' => ['required', 'integer'],
            'education_package_id' => ['nullable', 'integer'],
            'class_group_id' => ['nullable', 'integer'],
            'financial_guardian_id' => ['nullable', 'integer'],
            'discount_reason' => ['nullable', 'string', 'max:200'],
            'scholarship_reason' => ['nullable', 'string', 'max:200'],
            'create_contract' => ['boolean'],
            ...$this->priceRules(),
        ]);

        $student = Student::query()->findOrFail($data['student_id']);
        $term = AcademicTerm::query()->findOrFail($data['academic_term_id']);
        $program = Program::query()->findOrFail($data['program_id']);
        if (! empty($data['education_package_id'])) {
            EducationPackage::query()->findOrFail($data['education_package_id']);
        }
        if (! empty($data['class_group_id'])) {
            $group = ClassGroup::query()->findOrFail($data['class_group_id']);
            if ($group->program_id && $group->program_id !== $program->id) {
                throw new BusinessRuleException("{$group->name} sınıfı seçilen programa ait değil.", 'class_group_program_mismatch');
            }
        }
        if (! empty($data['financial_guardian_id']) && ! $student->guardians()->whereKey($data['financial_guardian_id'])->exists()) {
            throw new BusinessRuleException('Seçilen veli bu öğrenciye bağlı değil.', 'guardian_mismatch');
        }
        $duplicate = Enrollment::query()->where('student_id', $student->id)->where('academic_term_id', $term->id)->where('program_id', $program->id)
            ->whereIn('status', ['pending', 'active', 'frozen'])->exists();
        if ($duplicate) {
            throw new BusinessRuleException("{$student->full_name} bu dönemde {$program->name} programına zaten kayıtlı.", 'duplicate_enrollment');
        }

        $enrollment = DB::transaction(function () use ($service, $documents, $student, $data) {
            $enrollment = $service->enroll($student, [
                'academic_term_id' => (int) $data['academic_term_id'],
                'program_id' => (int) $data['program_id'],
                'education_package_id' => $data['education_package_id'] ?? null,
                'class_group_id' => $data['class_group_id'] ?? null,
                'list_price' => Money::of($data['list_price']),
                'discount_amount' => Money::of($data['discount_amount'] ?? '0'),
                'discount_reason' => $data['discount_reason'] ?? null,
                'scholarship_amount' => Money::of($data['scholarship_amount'] ?? '0'),
                'scholarship_reason' => $data['scholarship_reason'] ?? null,
                'enrolled_on' => $data['enrolled_on'],
                'financial_guardian_id' => $data['financial_guardian_id'] ?? null,
                'down_payment' => Money::of($data['down_payment'] ?? '0'),
                'installment_count' => (int) $data['installment_count'],
                'first_due_date' => $data['first_due_date'],
            ]);

            if ($data['create_contract'] ?? true) {
                $documents->prepareContract($enrollment);
            }

            return $enrollment;
        });

        return response()->json(['message' => 'Kayıt ve ödeme planı oluşturuldu.', 'id' => $enrollment->id, 'enrollment_no' => $enrollment->enrollment_no], 201);
    }

    public function restructure(Request $request, Enrollment $enrollment, InstallmentPlanService $plans): JsonResponse
    {
        $data = $this->validateTr($request, [
            'rows' => ['present', 'array', 'max:60'],
            'rows.*.id' => ['nullable', 'integer'],
            'rows.*.due_date' => ['required', 'date_format:Y-m-d'],
            'rows.*.amount' => ['required', 'string', self::MONEY],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['rows.*.amount.regex' => 'Taksit tutarlarını 1500 ya da 1500,50 biçiminde girin.']);

        $plans->restructure($enrollment, $data['rows'], $data['note'] ?? null);

        return $this->ok('Ödeme planı güncellendi.');
    }

    public function adjustPrice(Request $request, Enrollment $enrollment, InstallmentPlanService $plans): JsonResponse
    {
        $data = $this->validateTr($request, [
            'discount_amount' => ['required', 'string', self::MONEY],
            'discount_reason' => ['nullable', 'string', 'max:200'],
            'scholarship_amount' => ['required', 'string', self::MONEY],
            'scholarship_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $plans->adjustPrice($enrollment, $data);

        return $this->ok('İndirim / burs güncellendi; fark ödenmemiş taksitlere dağıtıldı.');
    }

    public function prepareContract(Enrollment $enrollment, FinanceDocuments $documents): JsonResponse
    {
        $contract = $documents->prepareContract($enrollment);

        return $this->ok('Sözleşme güncel bilgilerle hazırlandı.', ['contract_no' => $contract->contract_no]);
    }

    public function signContract(Request $request, Enrollment $enrollment, FinanceDocuments $documents): JsonResponse
    {
        $data = $this->validateTr($request, ['signed_by_name' => ['required', 'string', 'min:3', 'max:160']]);
        $contract = $documents->signContract($enrollment, $data['signed_by_name']);

        return $this->ok('Sözleşme imzalandı olarak kaydedildi; metin donduruldu.', ['contract_no' => $contract->contract_no]);
    }

    public function contractPdf(Request $request, Enrollment $enrollment, FinanceDocuments $documents): Response
    {
        // GET yan etki üretmez: sözleşme yoksa önce "Sözleşme hazırla" (POST) gerekir.
        $contract = Contract::query()->where('enrollment_id', $enrollment->id)->first()
            ?? throw new BusinessRuleException('Bu kayıt için sözleşme hazırlanmamış.', 'contract_missing', [], 404);

        return $documents->contractPdf($contract, $request->boolean('inline'));
    }

    private function priceRules(): array
    {
        return [
            'list_price' => ['required', 'string', self::MONEY],
            'discount_amount' => ['nullable', 'string', self::MONEY],
            'scholarship_amount' => ['nullable', 'string', self::MONEY],
            'down_payment' => ['nullable', 'string', self::MONEY],
            'installment_count' => ['required', 'integer', 'min:0', 'max:36'],
            'enrolled_on' => ['required', 'date_format:Y-m-d'],
            'first_due_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /** @return array{0:string, 1:list<array{due_date:string, amount:string}>} */
    private function plan(EnrollmentService $service, array $data): array
    {
        $net = bcsub(bcsub(Money::of($data['list_price']), Money::of($data['discount_amount'] ?? '0'), 2), Money::of($data['scholarship_amount'] ?? '0'), 2);
        if (bccomp($net, '0', 2) < 0) {
            throw new BusinessRuleException('İndirim ve burs toplamı liste fiyatını aşamaz.', 'negative_net_price');
        }

        return [$net, $service->buildPlan($net, Money::of($data['down_payment'] ?? '0'), (int) $data['installment_count'],
            CarbonImmutable::parse($data['enrolled_on']), CarbonImmutable::parse($data['first_due_date']))];
    }

    private function dec(mixed $v): string
    {
        return bcadd((string) ($v ?? '0'), '0', 2);
    }
}
