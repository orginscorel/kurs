<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\Contract;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use App\Support\Audit;
use App\Support\InstitutionFormat;
use App\Support\Money;
use App\Support\Sequence;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Finans belgeleri: tahsilat makbuzu ve kayıt sözleşmesi (PDF, markalı şablon).
 * Sözleşme metni imza anında body_snapshot'a kopyalanır; imzadan sonra değişmez, PDF bu kopyadan üretilir.
 */
class FinanceDocuments
{
    public function receiptPdf(Payment $payment, bool $inline = false): Response
    {
        // Kurumsal ortak düzen (toplu basımla aynı şablon): FinanceExtraDocuments
        return app(FinanceExtraDocuments::class)->receipts(collect([$payment]), $inline);
    }

    /** Sözleşme oluşturur ya da (imzasızsa) metnini günceller. */
    public function prepareContract(Enrollment $enrollment): Contract
    {
        return DB::transaction(function () use ($enrollment) {
            /** @var Contract|null $contract */
            $contract = Contract::query()->where('enrollment_id', $enrollment->id)->lockForUpdate()->first();

            if ($contract?->signed_at) {
                throw new BusinessRuleException('Sözleşme imzalanmış; metni değiştirilemez.', 'contract_signed');
            }

            $body = $this->contractBody($enrollment);

            if (! $contract) {
                $contract = Contract::query()->create([
                    'enrollment_id' => $enrollment->id,
                    'contract_no' => Sequence::next('contract', 'SZL', $enrollment->branch_id),
                    'body_snapshot' => $body,
                ]);
                Audit::log('contract.created', "{$enrollment->enrollment_no} kaydı için {$contract->contract_no} numaralı sözleşmeyi hazırladı.", $enrollment);
            } else {
                $contract->forceFill(['body_snapshot' => $body])->save();
                Audit::log('contract.refreshed', "{$contract->contract_no} numaralı sözleşmenin metnini güncel kayıt bilgileriyle yeniledi.", $enrollment);
            }

            return $contract;
        });
    }

    /** İmza: metin o anki kayıt bilgileriyle son kez üretilir ve donar. */
    public function signContract(Enrollment $enrollment, string $signedByName): Contract
    {
        $signedByName = trim($signedByName);
        if (mb_strlen($signedByName) < 3) {
            throw new BusinessRuleException('İmzalayan kişinin adını girin.', 'signer_required');
        }

        return DB::transaction(function () use ($enrollment, $signedByName) {
            $contract = $this->prepareContract($enrollment);
            $contract = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $contract->forceFill([
                'body_snapshot' => $this->contractBody($enrollment, $signedByName, now()),
                'signed_at' => now(),
                'signed_by_name' => mb_substr($signedByName, 0, 160),
            ])->save();

            Audit::log('contract.signed', "{$contract->contract_no} numaralı sözleşmeyi {$signedByName} tarafından imzalandı olarak kaydetti; metin donduruldu.", $enrollment);

            return $contract;
        });
    }

    public function contractPdf(Contract $contract, bool $inline = false): Response
    {
        $pdf = Pdf::loadView('pdf.finance.contract', [
            'institution' => $this->institution(),
            'contract' => $contract,
        ])->setPaper('a4', 'portrait');

        $name = "sozlesme-{$contract->contract_no}.pdf";

        return $inline ? $pdf->stream($name) : $pdf->download($name);
    }

    public function contractBody(Enrollment $enrollment, ?string $signedBy = null, ?\DateTimeInterface $signedAt = null): string
    {
        $enrollment->loadMissing(['student', 'program', 'term', 'package', 'classGroup', 'financialGuardian', 'installments']);
        $student = $enrollment->student;
        $guardian = $enrollment->financialGuardian
            ?? $student?->guardians()->orderByDesc('guardian_student.is_financially_responsible')->orderByDesc('guardian_student.is_primary')->first();

        return view('pdf.finance.contract-body', [
            'institution' => $this->institution(),
            'enrollment' => $enrollment,
            'student' => $student,
            'guardian' => $guardian,
            'installments' => $enrollment->installments->where('status', '!=', 'cancelled')->values(),
            'money' => fn ($v) => Money::format($v),
            'netWords' => AmountInWords::lira((string) $enrollment->net_price),
            'signedBy' => $signedBy,
            'signedAt' => $signedAt,
        ])->render();
    }

    /** @return array<string, mixed> */
    public function institution(): array
    {
        $inst = Settings::group('institution');
        // Kurum ayarlarının belgeye yansıması: para simgesi, kurum saat dilimindeki üretim zamanı, vergi/web alt bilgisi
        $inst['currency_symbol'] = InstitutionFormat::currencySymbol($inst['currency'] ?? 'TRY');
        $inst['generated_at'] = InstitutionFormat::stamp($inst);
        $inst['generated_date'] = InstitutionFormat::stamp($inst, 'd.m.Y');
        $inst['footer_line'] = InstitutionFormat::footerLine($inst);
        $inst['logo_data'] = null;
        if (! empty($inst['logo_path']) && Storage::disk('public')->exists($inst['logo_path'])) {
            $mime = Storage::disk('public')->mimeType($inst['logo_path']) ?: 'image/png';
            if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                $inst['logo_data'] = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($inst['logo_path']));
            }
        }

        return $inst;
    }
}
