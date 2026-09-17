<?php

namespace App\Services\Finance;

use App\Exceptions\BusinessRuleException;
use App\Models\Guardian;
use App\Models\Installment;
use App\Models\PromissoryNote;
use App\Services\Accounting\FinanceSettings;
use App\Support\Money;
use App\Support\Sensitive;
use App\Support\Sequence;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Senet (bono) basımı: her taksit için bir senet. Basılan senet kayda alınır (no, basım sayısı/tarihi);
 * tekrar basımda isteğe bağlı "SURETİDİR" ibaresi. Taksit ödendiyse/iptalse senet üzerinde belirtilir.
 * Basılmış senedin tutarı/vadesi taksitten farklılaşırsa eski senet iptal edilir, yeni numara verilir.
 * UYARI: Senet metni TTK m.776 zorunlu unsurlarına göre hazırlanmıştır; kurum hukukçusu/muhasebecisiyle teyit edilmelidir.
 */
class PromissoryNoteService
{
    public const MAX = 600;

    /** Filtreye uyan taksitler. */
    public function query(array $f)
    {
        $q = Installment::query()->from('installments')
            ->join('enrollments as e', 'e.id', '=', 'installments.enrollment_id')
            ->join('students as s', 's.id', '=', 'installments.student_id')
            ->whereNull('e.deleted_at')->whereNull('s.deleted_at')
            ->select('installments.*');
        if (! empty($f['installment_ids'])) {
            $q->whereIn('installments.id', $f['installment_ids']);
        }
        if (! empty($f['enrollment_ids'])) {
            $q->whereIn('installments.enrollment_id', $f['enrollment_ids']);
        }
        foreach (['term_id' => 'e.academic_term_id', 'program_id' => 'e.program_id', 'class_group_id' => 'e.class_group_id', 'student_id' => 'installments.student_id'] as $k => $col) {
            if (! empty($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (! empty($f['due_from'])) {
            $q->where('installments.due_date', '>=', $f['due_from']);
        }
        if (! empty($f['due_to'])) {
            $q->where('installments.due_date', '<=', $f['due_to']);
        }
        $q->when(empty($f['include_paid']), fn ($x) => $x->whereIn('installments.status', ['pending', 'partial', 'overdue']),
            fn ($x) => $x->where('installments.status', '!=', 'cancelled'));
        if (! empty($f['q'])) {
            $q->where(fn ($w) => $w->where('s.full_name', 'like', '%'.$f['q'].'%')->orWhere('e.enrollment_no', $f['q'])->orWhere('s.student_no', $f['q']));
        }

        return $q->orderBy('s.full_name')->orderBy('installments.enrollment_id')->orderBy('installments.sequence');
    }

    /**
     * Basım öncesi önizleme: borçlu bilgisi, eksik alanlar, mevcut senet.
     *
     * @param Collection<int, Installment> $installments
     */
    public function preview(Collection $installments, bool $withTaxId = false): array
    {
        $installments = new EloquentCollection($installments->all());
        $installments->loadMissing(['student', 'enrollment.program']);
        $debtors = $this->debtors($installments->pluck('enrollment')->unique('id'));
        $notes = PromissoryNote::query()->whereIn('installment_id', $installments->pluck('id'))->whereNull('voided_at')->get()->keyBy('installment_id');
        $rows = [];
        $missing = [];
        foreach ($installments as $i) {
            $d = $debtors[$i->enrollment_id] ?? null;
            $lack = array_values(array_filter([
                ! $d || ! $d['name'] ? 'borçlu adı' : null,
                ! $d || ! $d['tax_id'] ? 'TCKN' : null,
                ! $d || ! $d['address'] ? 'adres' : null,
            ]));
            $n = $notes[$i->id] ?? null;
            $row = [
                'installment_id' => $i->id, 'student_id' => $i->student_id, 'student' => $i->student?->full_name, 'enrollment_no' => $i->enrollment?->enrollment_no,
                'program' => $i->enrollment?->program?->name, 'sequence' => $i->sequence, 'due_date' => $i->due_date->toDateString(),
                'amount' => (string) $i->amount, 'remaining' => $i->remaining(), 'status' => $i->status,
                'debtor' => $d['name'] ?? null, 'debtor_kind' => $d['kind'] ?? null,
                'debtor_tax_id' => $withTaxId ? ($d['tax_id'] ?? null) : (($d['tax_id'] ?? null) ? Sensitive::maskNationalId(substr($d['tax_id'], -4)) : null),
                'missing' => $lack,
                'note' => $n ? ['id' => $n->id, 'note_no' => $n->note_no, 'print_count' => $n->print_count, 'last_printed_at' => $n->last_printed_at?->toAtomString(),
                    'stale' => bccomp((string) $n->amount, (string) $i->amount, 2) !== 0 || $n->due_date->toDateString() !== $i->due_date->toDateString()] : null,
            ];
            $rows[] = $row;
            if ($lack) {
                $missing[$i->enrollment_id] ??= ['enrollment_no' => $row['enrollment_no'], 'student' => $row['student'], 'student_id' => $i->student_id, 'debtor' => $row['debtor'], 'missing' => $lack, 'count' => 0];
                $missing[$i->enrollment_id]['count']++;
            }
        }

        return [
            'rows' => $rows,
            'missing' => array_values($missing),
            'total' => array_reduce($rows, fn ($s, $r) => bcadd($s, $r['amount'], 2), '0.00'),
            'printed' => count(array_filter($rows, fn ($r) => $r['note'] && $r['note']['print_count'] > 0)),
        ];
    }

    /**
     * Taksitlerin senet kayıtlarını hazırlar (yoksa oluşturur, değişmişse yeniler).
     *
     * @param Collection<int, Installment> $installments
     * @return Collection<int, PromissoryNote>
     */
    public function prepare(Collection $installments): Collection
    {
        if ($installments->count() > self::MAX) {
            throw new BusinessRuleException('Tek seferde en çok '.self::MAX.' senet basılabilir; filtreyi daraltın.', 'too_many_notes');
        }
        $installments = new EloquentCollection($installments->all());
        $installments->loadMissing(['enrollment']);
        $debtors = $this->debtors($installments->pluck('enrollment')->unique('id'));
        $s = $this->settings();

        return DB::transaction(function () use ($installments, $debtors, $s) {
            $out = collect();
            foreach ($installments as $i) {
                if ($i->status === 'cancelled') {
                    continue;
                }
                $d = $debtors[$i->enrollment_id] ?? ['name' => null, 'tax_id' => null, 'address' => null, 'guardian_id' => null];
                /** @var PromissoryNote|null $note */
                $note = PromissoryNote::query()->where('installment_id', $i->id)->whereNull('voided_at')->lockForUpdate()->first();
                $changed = $note && (bccomp((string) $note->amount, (string) $i->amount, 2) !== 0 || $note->due_date->toDateString() !== $i->due_date->toDateString());
                if ($note && $changed && $note->print_count > 0) {
                    $note->forceFill(['voided_at' => now(), 'void_reason' => 'Taksit tutarı/vadesi değişti; yeni senet düzenlendi.'])->save();
                    $note = null;
                }
                $values = [
                    'amount' => $i->amount, 'due_date' => $i->due_date->toDateString(),
                    'debtor_name' => mb_substr($d['name'] ?? '', 0, 200), 'debtor_tax_id' => $d['tax_id'] ?: null,
                    'debtor_address' => $d['address'] ? mb_substr($d['address'], 0, 400) : null, 'guardian_id' => $d['guardian_id'],
                    'payee_name' => $s['payee'], 'payment_place' => $s['place'], 'issue_place' => $s['place'],
                ];
                if ($note) {
                    if ($note->print_count === 0) {
                        $note->forceFill($values)->save();   // basılmamışsa güncel bilgiyle tazelenir
                    }
                } else {
                    $note = PromissoryNote::query()->create($values + [
                        'branch_id' => $i->branch_id,
                        'note_no' => Sequence::next('promissory_note', (string) Settings::get('finance.note_prefix', 'SNT'), $i->branch_id),
                        'installment_id' => $i->id, 'enrollment_id' => $i->enrollment_id, 'student_id' => $i->student_id,
                        'issue_date' => ($i->enrollment?->enrolled_on ?? now())->toDateString(),
                        'created_by' => Auth::id(),
                    ]);
                }
                $out->push($note);
            }

            return $out;
        });
    }

    /**
     * @param Collection<int, PromissoryNote> $notes
     * @param int $perPage 1 = A5 yatay (sayfa başına bir senet), 2 ya da 3 = A4 dikey
     */
    public function pdf(Collection $notes, int $perPage = 3, bool $copyMark = true, bool $inline = false): Response
    {
        if ($notes->isEmpty()) {
            throw new BusinessRuleException('Basılacak senet yok (iptal ya da seçilmemiş taksitler).', 'no_notes');
        }
        $perPage = in_array($perPage, [1, 2, 3], true) ? $perPage : 3;
        $notes = new EloquentCollection($notes->all());
        $notes->loadMissing(['installment', 'student', 'enrollment.program']);
        $s = $this->settings();
        $counts = DB::table('installments')->whereIn('enrollment_id', $notes->pluck('enrollment_id')->unique())->where('status', '!=', 'cancelled')
            ->groupBy('enrollment_id')->selectRaw('enrollment_id, COUNT(*) AS c')->pluck('c', 'enrollment_id');

        $items = $notes->map(function (PromissoryNote $n) use ($copyMark, $counts) {
            $inst = $n->installment;
            $stamp = match (true) {
                $n->voided_at !== null => 'İPTAL',
                $inst?->status === 'cancelled' => 'İPTAL',
                $inst?->status === 'paid' => 'ÖDENDİ',
                $copyMark && $n->print_count > 0 => 'SURETİDİR',
                default => null,
            };

            return [
                'n' => $n,
                'amount' => Money::format($n->amount),
                'words' => AmountInWords::lira((string) $n->amount),
                'tax_id' => $n->debtor_tax_id,
                'stamp' => $stamp,
                'seq' => ($inst?->sequence ?? '?').'/'.($counts[$n->enrollment_id] ?? '?'),
            ];
        })->values();

        $pdf = Pdf::loadView('pdf.finance.notes', [
            'institution' => app(FinanceDocuments::class)->institution(),
            'items' => $items,
            'perPage' => $perPage,
            's' => $s,
        ])->setPaper($perPage === 1 ? 'a5' : 'a4', $perPage === 1 ? 'landscape' : 'portrait');

        // Basım kaydı (PDF üretildikten sonra; basım sayısı ve son basan)
        PromissoryNote::query()->whereIn('id', $notes->pluck('id'))->update([
            'print_count' => DB::raw('print_count + 1'),
            'first_printed_at' => DB::raw('COALESCE(first_printed_at, CURRENT_TIMESTAMP)'),
            'last_printed_at' => now(),
            'last_printed_by' => Auth::id(),
            'updated_at' => now(),
        ]);
        FinanceAudit::log('promissory_note.printed', sprintf('%d senet bastı (%s).', $notes->count(), $notes->pluck('note_no')->take(5)->join(', ').($notes->count() > 5 ? '…' : '')));

        $name = $notes->count() === 1 ? "senet-{$notes->first()->note_no}.pdf" : 'senetler-'.now()->format('Y-m-d-His').'.pdf';

        return $inline ? $pdf->stream($name) : $pdf->download($name);
    }

    /** @return array{payee:string, place:string, court:string, consideration:string, acceleration:bool} */
    public function settings(): array
    {
        $inst = Settings::group('institution');
        $a = FinanceSettings::all();

        return [
            'payee' => (string) ($a['note_payee'] ?? null ?: ($inst['name'] ?? 'Kurum')),
            'place' => (string) ($a['note_place'] ?? null ?: ($inst['address'] ?? '')),
            'court' => (string) ($a['note_court'] ?? null ?: 'Erbaa'),
            'consideration' => (string) ($a['note_consideration'] ?? null ?: 'eğitim hizmeti karşılığı'),
            'acceleration' => (bool) ($a['note_acceleration'] ?? true),
        ];
    }

    /**
     * Kayıt başına borçlu: kayıtta seçilen ödeme sorumlusu → öğrenciye bağlı ödeme sorumlusu/ birincil veli → öğrenci.
     *
     * @return array<int, array{name:?string, tax_id:?string, address:?string, guardian_id:?int, kind:string}>
     */
    public function debtors(Collection $enrollments): array
    {
        $out = [];
        $studentIds = $enrollments->pluck('student_id')->filter()->unique();
        $pivot = DB::table('guardian_student as gs')->join('guardians as g', 'g.id', '=', 'gs.guardian_id')->whereNull('g.deleted_at')
            ->whereIn('gs.student_id', $studentIds)->orderByDesc('gs.is_financially_responsible')->orderByDesc('gs.is_primary')
            ->get(['gs.student_id', 'g.id'])->groupBy('student_id');
        $guardianIds = $enrollments->pluck('financial_guardian_id')->filter()->merge($pivot->map(fn ($g) => $g->first()->id)->values())->unique();
        $guardians = Guardian::query()->whereIn('id', $guardianIds)->get()->keyBy('id');
        $students = DB::table('students')->whereIn('id', $studentIds)->get(['id', 'full_name', 'national_id_encrypted', 'address'])->keyBy('id');
        foreach ($enrollments as $e) {
            if (! $e) {
                continue;
            }
            $gid = $e->financial_guardian_id ?: ($pivot[$e->student_id][0]->id ?? null);
            $g = $gid ? ($guardians[$gid] ?? null) : null;
            $st = $students[$e->student_id] ?? null;
            $out[$e->id] = $g
                ? ['name' => trim($g->first_name.' '.$g->last_name), 'tax_id' => Sensitive::decrypt($g->national_id_encrypted), 'address' => $g->address, 'guardian_id' => $g->id, 'kind' => 'guardian']
                : ['name' => $st?->full_name, 'tax_id' => Sensitive::decrypt($st?->national_id_encrypted), 'address' => $st?->address, 'guardian_id' => null, 'kind' => 'student'];
        }

        return $out;
    }
}
