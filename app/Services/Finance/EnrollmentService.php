<?php

namespace App\Services\Finance;

use App\Events\EnrollmentCreated;
use App\Exceptions\BusinessRuleException;
use App\Models\ActivityFeed;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Models\Student;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Kayıt + ücret + ödeme planı. Tek transaction: yarım kalan kayıt olmaz.
 */
class EnrollmentService
{
    /**
     * @param array{
     *   academic_term_id:int, program_id:int, education_package_id?:?int, class_group_id?:?int,
     *   list_price:string|float, discount_amount?:string|float, discount_reason?:?string,
     *   scholarship_amount?:string|float, scholarship_reason?:?string, enrolled_on:string,
     *   financial_guardian_id?:?int, down_payment?:string|float, installment_count:int,
     *   first_due_date:string, day_of_month?:?int
     * } $data
     */
    public function enroll(Student $student, array $data): Enrollment
    {
        $list = Money::of($data['list_price']);
        $discount = Money::of($data['discount_amount'] ?? 0);
        $scholarship = Money::of($data['scholarship_amount'] ?? 0);
        $net = bcsub(bcsub($list, $discount, 2), $scholarship, 2);

        if (bccomp($net, '0', 2) < 0) {
            throw new BusinessRuleException('İndirim ve burs toplamı liste fiyatını aşamaz.', 'negative_net_price');
        }

        $plan = $this->buildPlan(
            $net,
            Money::of($data['down_payment'] ?? 0),
            (int) $data['installment_count'],
            CarbonImmutable::parse($data['enrolled_on']),
            CarbonImmutable::parse($data['first_due_date']),
        );

        return DB::transaction(function () use ($student, $data, $list, $discount, $scholarship, $net, $plan) {
            $enrollment = Enrollment::query()->create([
                'student_id' => $student->id,
                'academic_term_id' => $data['academic_term_id'],
                'program_id' => $data['program_id'],
                'education_package_id' => $data['education_package_id'] ?? null,
                'class_group_id' => $data['class_group_id'] ?? null,
                'enrollment_no' => Sequence::next('enrollment', Settings::get('finance.enrollment_prefix', 'KYT')),
                'list_price' => $list,
                'discount_amount' => $discount,
                'discount_reason' => $data['discount_reason'] ?? null,
                'scholarship_amount' => $scholarship,
                'scholarship_reason' => $data['scholarship_reason'] ?? null,
                'net_price' => $net,
                'status' => 'active',
                'enrolled_on' => $data['enrolled_on'],
                'financial_guardian_id' => $data['financial_guardian_id'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($plan as $i => $row) {
                Installment::query()->create([
                    'enrollment_id' => $enrollment->id,
                    'student_id' => $student->id,
                    'sequence' => $i + 1,
                    'due_date' => $row['due_date'],
                    'amount' => $row['amount'],
                ]);
            }

            if (! empty($data['class_group_id'])) {
                $this->assignClassGroup($student, ClassGroup::query()->findOrFail($data['class_group_id']), $data['enrolled_on']);
            }

            if (! in_array($student->status, ['active'], true)) {
                $student->forceFill(['status' => 'active', 'registered_on' => $student->registered_on ?? $data['enrolled_on']])->save();
            }

            ActivityFeed::query()->create([
                'kind' => 'enrollment',
                'message' => "{$student->full_name} kaydı tamamlandı",
                'subject_type' => $enrollment->getMorphClass(),
                'subject_id' => $enrollment->id,
                'student_id' => $student->id,
                'occurred_at' => now(),
            ]);

            Audit::log('enrollment.created', sprintf(
                '%s öğrencisi için %s TL net bedelli kayıt oluşturdu (%d taksit).',
                $student->full_name, Money::format($net), count($plan),
            ), $enrollment);

            DB::afterCommit(fn () => event(new EnrollmentCreated($enrollment->id)));

            return $enrollment->load('installments');
        });
    }

    /**
     * Ödeme planı: peşinat (kayıt günü vadeli 1. taksit) + eşit aylık taksitler.
     * Kuruş farkı son taksite eklenir; toplam her zaman net bedele eşittir.
     *
     * @return list<array{due_date: string, amount: string}>
     */
    public function buildPlan(string $net, string $downPayment, int $count, CarbonImmutable $enrolledOn, CarbonImmutable $firstDue): array
    {
        if (bccomp($net, '0', 2) === 0) {
            return [];
        }
        if (bccomp($downPayment, $net, 2) > 0) {
            throw new BusinessRuleException('Peşinat net bedelden büyük olamaz.', 'down_payment_too_high');
        }
        if ($count < 0 || $count > 36) {
            throw new BusinessRuleException('Taksit sayısı 0 ile 36 arasında olmalı.', 'invalid_installment_count');
        }

        $rows = [];
        $remaining = bcsub($net, $downPayment, 2);

        if (bccomp($downPayment, '0', 2) > 0) {
            $rows[] = ['due_date' => $enrolledOn->toDateString(), 'amount' => $downPayment];
        }

        if (bccomp($remaining, '0', 2) > 0) {
            if ($count === 0) {
                throw new BusinessRuleException('Kalan tutar için en az bir taksit gerekir.', 'installments_required');
            }

            $each = bcdiv($remaining, (string) $count, 2);
            $allocated = '0.00';

            for ($i = 0; $i < $count; $i++) {
                $amount = $i === $count - 1 ? bcsub($remaining, $allocated, 2) : $each;
                $allocated = bcadd($allocated, $amount, 2);
                // Ay sonu taşmasını önle: 31 Ocak + 1 ay = 28/29 Şubat
                $rows[] = ['due_date' => $firstDue->addMonthsNoOverflow($i)->toDateString(), 'amount' => $amount];
            }
        }

        return $rows;
    }

    public function assignClassGroup(Student $student, ClassGroup $group, string $joinedOn): void
    {
        $activeCount = $group->activeStudents()->count();
        $alreadyMember = $group->activeStudents()->whereKey($student->id)->exists();

        if ($alreadyMember) {
            return;
        }

        if ($activeCount >= $group->capacity) {
            throw new BusinessRuleException("{$group->name} sınıfı dolu ({$group->capacity} kişi).", 'class_group_full');
        }

        // Aynı programdaki önceki aktif sınıf üyeliğini kapat (sınıf değişikliği geçmişi korunur).
        DB::table('class_group_student')
            ->where('student_id', $student->id)
            ->whereNull('left_on')
            ->whereIn('class_group_id', ClassGroup::query()->where('academic_term_id', $group->academic_term_id)->select('id'))
            ->update(['left_on' => $joinedOn, 'updated_at' => now()]);

        $student->classGroups()->attach($group->id, ['joined_on' => $joinedOn]);
    }
}
