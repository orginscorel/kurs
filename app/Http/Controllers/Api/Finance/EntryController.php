<?php

namespace App\Http\Controllers\Api\Finance;

use App\Services\Finance\FinanceAudit;
use App\Exceptions\BusinessRuleException;
use App\Models\FinanceCategory;
use App\Models\FinanceEntry;
use App\Models\User;
use App\Services\Finance\FinanceEntryService;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntryController extends FinanceController
{
    public function __construct(private readonly FinanceEntryService $entries) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->filtered($request);
        $totals = (clone $query)->setEagerLoads([])->reorder()->select([])->selectRaw("
            COALESCE(SUM(CASE WHEN finance_entries.voided_at IS NULL AND finance_entries.direction = 'income' THEN finance_entries.amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN finance_entries.voided_at IS NULL AND finance_entries.direction = 'expense' THEN finance_entries.amount ELSE 0 END), 0) AS expense,
            SUM(finance_entries.voided_at IS NOT NULL) AS voided_count, COUNT(*) AS count")->first();

        $this->applySort($query, $request, ['entry_date' => 'finance_entries.entry_date', 'amount' => 'finance_entries.amount'], '-entry_date');
        $query->orderByDesc('finance_entries.id');

        $income = bcadd((string) $totals->income, '0', 2);
        $expense = bcadd((string) $totals->expense, '0', 2);

        return $this->paginated($query->paginate($this->perPage($request)), fn (FinanceEntry $e) => $this->row($e), [
            'totals' => ['income' => $income, 'expense' => $expense, 'net' => bcsub($income, $expense, 2), 'count' => (int) $totals->count, 'voided_count' => (int) $totals->voided_count],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->orderBy('finance_entries.entry_date');
        FinanceAudit::log('finance_entry.exported', 'gelir/gider listesini Excel olarak dışa aktardı.');

        return $this->xlsx('gelir-gider-'.now()->format('Y-m-d').'.xlsx',
            ['Tarih', 'Tür', 'Kategori', 'Açıklama', 'Karşı taraf', 'Belge no', 'Hesap', 'Tutar', 'Kaydeden', 'Durum', 'İptal gerekçesi'],
            function (callable $add) use ($query) {
                $query->chunk(500, function ($chunk) use ($add) {
                    foreach ($chunk as $e) {
                        $add([$e->entry_date->format('d.m.Y'), FinanceEntryService::DIRECTIONS[$e->direction], $e->category?->name ?? '', $e->description, $e->counterparty ?? '',
                            $e->document_no ?? '', $e->account?->name ?? '', $this->cell($e->direction === 'expense' ? '-'.$e->amount : $e->amount), $e->creator?->name ?? '',
                            $e->voided_at ? 'İptal' : 'Geçerli', $e->void_reason ?? '']);
                    }
                });
            });
    }

    public function show(FinanceEntry $entry): JsonResponse
    {
        $entry->load(['category', 'account', 'creator']);

        return response()->json(['data' => [
            ...$this->row($entry),
            'voided_by' => $entry->voided_by ? User::query()->whereKey($entry->voided_by)->value('name') : null,
            'stock_movement' => DB::table('stock_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.finance_entry_id', $entry->id)
                ->first(['m.id', 'm.quantity', 'm.kind', 'p.name as product']),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'direction' => ['required', Rule::in(array_keys(FinanceEntryService::DIRECTIONS))],
            'finance_category_id' => ['required', 'integer'],
            'finance_account_id' => ['required', 'integer'],
            'amount' => ['required', 'string', self::MONEY],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'min:3', 'max:500'],
            'counterparty' => ['nullable', 'string', 'max:160'],
            'document_no' => ['nullable', 'string', 'max:60'],
        ], ['amount.regex' => 'Tutarı 1500 ya da 1500,50 biçiminde girin.']);

        $entry = $this->entries->record($data);

        return response()->json(['message' => $data['direction'] === 'income' ? 'Gelir kaydedildi.' : 'Gider kaydedildi.', 'id' => $entry->id], 201);
    }

    public function void(Request $request, FinanceEntry $entry): JsonResponse
    {
        $data = $this->validateTr($request, ['reason' => ['required', 'string', 'min:5', 'max:300']], ['reason.min' => 'İptal gerekçesi en az 5 karakter olmalı.']);
        $this->entries->void($entry, $data['reason']);

        return $this->ok('Kayıt iptal edildi; hesap bakiyesi ters kayıtla düzeltildi.');
    }

    // ------------------------------------------------------------------ kategoriler

    public function categories(): JsonResponse
    {
        $usage = DB::table('finance_entries')->where('branch_id', $this->branchId())->whereNull('voided_at')
            ->groupBy('finance_category_id')->selectRaw('finance_category_id, COUNT(*) AS c')->pluck('c', 'finance_category_id');

        return response()->json(['data' => FinanceCategory::query()->orderBy('direction')->orderByDesc('is_system')->orderBy('name')->get()
            ->map(fn (FinanceCategory $c) => [
                'id' => $c->id, 'direction' => $c->direction, 'code' => $c->code, 'name' => $c->name, 'is_system' => $c->is_system,
                'usage' => (int) ($usage[$c->id] ?? 0), 'selectable' => $c->code !== 'student_payment',
            ])]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $this->validateTr($request, [
            'direction' => ['required', Rule::in(array_keys(FinanceEntryService::DIRECTIONS))],
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $name = trim($data['name']);
        if (FinanceCategory::query()->where('direction', $data['direction'])->where('name', $name)->exists()) {
            throw new BusinessRuleException('Bu adla bir kategori zaten var.', 'duplicate_category');
        }
        $base = Str::slug($name, '_') ?: 'kategori';
        $code = $base;
        for ($i = 2; FinanceCategory::query()->where('direction', $data['direction'])->where('code', $code)->exists(); $i++) {
            $code = mb_substr($base, 0, 34).'_'.$i;
        }

        $category = FinanceCategory::query()->create(['direction' => $data['direction'], 'code' => mb_substr($code, 0, 40), 'name' => $name, 'is_system' => false]);
        FinanceAudit::log('finance_category.created', sprintf('"%s" %s kategorisini ekledi.', $name, mb_strtolower(FinanceEntryService::DIRECTIONS[$data['direction']])), $category);

        return response()->json(['message' => 'Kategori eklendi.', 'id' => $category->id], 201);
    }

    public function updateCategory(Request $request, FinanceCategory $category): JsonResponse
    {
        $data = $this->validateTr($request, ['name' => ['required', 'string', 'min:2', 'max:120']]);
        if ($category->is_system) {
            throw new BusinessRuleException('Sistem kategorileri değiştirilemez.', 'system_category');
        }
        $old = $category->name;
        $category->forceFill(['name' => trim($data['name'])])->save();
        FinanceAudit::log('finance_category.updated', "\"{$old}\" kategorisinin adını \"{$category->name}\" olarak değiştirdi.", $category);

        return $this->ok('Kategori güncellendi.');
    }

    public function destroyCategory(FinanceCategory $category): JsonResponse
    {
        if ($category->is_system) {
            throw new BusinessRuleException('Sistem kategorileri silinemez.', 'system_category');
        }
        if (DB::table('finance_entries')->where('finance_category_id', $category->id)->exists()) {
            throw new BusinessRuleException('Bu kategoride kayıt var; silinemez. Adını değiştirebilirsiniz.', 'category_in_use');
        }
        $category->delete();
        FinanceAudit::log('finance_category.deleted', "\"{$category->name}\" kategorisini sildi.", $category);

        return $this->ok('Kategori silindi.');
    }

    private function filtered(Request $request): Builder
    {
        $query = FinanceEntry::query()->select('finance_entries.*')->with(['category:id,name,code,direction', 'account:id,name,kind', 'creator:id,name']);

        if (($dir = $request->query('direction')) && isset(FinanceEntryService::DIRECTIONS[$dir])) {
            $query->where('finance_entries.direction', $dir);
        }
        if ($c = $request->integer('category_id')) {
            $query->where('finance_entries.finance_category_id', $c);
        }
        if ($a = $request->integer('account_id')) {
            $query->where('finance_entries.finance_account_id', $a);
        }
        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn (Builder $w) => $w->where('finance_entries.description', 'like', '%'.$q.'%')
                ->orWhere('finance_entries.counterparty', 'like', '%'.$q.'%')->orWhere('finance_entries.document_no', $q));
        }
        match ($request->query('status')) {
            'active' => $query->whereNull('finance_entries.voided_at'),
            'voided' => $query->whereNotNull('finance_entries.voided_at'),
            default => null,
        };
        if ($from = $request->date('from')) {
            $query->where('finance_entries.entry_date', '>=', $from->toDateString());
        }
        if ($to = $request->date('to')) {
            $query->where('finance_entries.entry_date', '<=', $to->toDateString());
        }

        return $query;
    }

    private function row(FinanceEntry $e): array
    {
        return [
            'id' => $e->id, 'direction' => $e->direction, 'amount' => (string) $e->amount, 'entry_date' => $e->entry_date->toDateString(),
            'description' => $e->description, 'counterparty' => $e->counterparty, 'document_no' => $e->document_no,
            'category' => $e->category ? ['id' => $e->category->id, 'name' => $e->category->name] : null,
            'account' => $e->account ? ['id' => $e->account->id, 'name' => $e->account->name] : null,
            'created_by' => $e->creator?->name, 'created_at' => $e->created_at?->toAtomString(),
            'voided_at' => $e->voided_at?->toAtomString(), 'void_reason' => $e->void_reason,
        ];
    }
}
