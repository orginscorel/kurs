<?php

namespace App\Services\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\FinanceAccount;
use App\Models\FinanceCategory;
use App\Models\LedgerAccount;
use App\Models\LedgerMapping;
use App\Services\Finance\FinanceAudit;
use Illuminate\Support\Facades\DB;

/**
 * Tek Düzen Hesap Planı'na (TDHP) uygun sade hesap planı ve kaynak → hesap kodu eşlemesi.
 * Varsayılanlar şube başına bir kez yazılır (idempotent); kod ve eşlemeler ekrandan değiştirilebilir.
 * NOT: Eşlemeler kurum muhasebecisiyle teyit edilmelidir.
 */
class ChartOfAccounts
{
    /** @var list<array{0:string,1:string,2:string,3:string}> kod, ad, tür, normal taraf */
    public const DEFAULTS = [
        ['100', 'Kasa', 'asset', 'debit'],
        ['101', 'Alınan çekler', 'asset', 'debit'],
        ['102', 'Bankalar', 'asset', 'debit'],
        ['108', 'Diğer hazır değerler (POS)', 'asset', 'debit'],
        ['120', 'Alıcılar', 'asset', 'debit'],
        ['153', 'Ticari mallar', 'asset', 'debit'],
        ['191', 'İndirilecek KDV', 'asset', 'debit'],
        ['320', 'Satıcılar', 'liability', 'credit'],
        ['335', 'Personele borçlar', 'liability', 'credit'],
        ['340', 'Alınan sipariş avansları', 'liability', 'credit'],
        ['360', 'Ödenecek vergi ve fonlar', 'liability', 'credit'],
        ['391', 'Hesaplanan KDV', 'liability', 'credit'],
        ['500', 'Sermaye', 'equity', 'credit'],
        ['600', 'Yurt içi satışlar', 'income', 'credit'],
        ['602', 'Diğer gelirler', 'income', 'credit'],
        ['610', 'Satıştan iadeler (-)', 'income', 'debit'],
        ['611', 'Satış iskontoları (-)', 'income', 'debit'],
        ['649', 'Diğer olağan gelir ve kârlar', 'income', 'credit'],
        ['653', 'Komisyon giderleri', 'expense', 'debit'],
        ['659', 'Diğer olağan gider ve zararlar', 'expense', 'debit'],
        ['760', 'Pazarlama, satış ve dağıtım giderleri', 'expense', 'debit'],
        ['770', 'Genel yönetim giderleri', 'expense', 'debit'],
    ];

    /** Özel roller (sistem işlemlerinin karşı hesapları). */
    public const ROLES = [
        'cash' => ['100', 'Kasa hesapları (varsayılan)'],
        'bank' => ['102', 'Banka hesapları (varsayılan)'],
        'pos' => ['108', 'POS hesapları (varsayılan)'],
        'advance' => ['340', 'Faturalanmamış tahsilat (alınan avans)'],
        'receivable' => ['120', 'Faturalı alacak'],
        'sales' => ['600', 'Eğitim hizmeti satışı'],
        'sales_return' => ['610', 'Satıştan iade'],
        'vat_output' => ['391', 'Hesaplanan KDV'],
        'opening_equity' => ['500', 'Açılış bakiyesi karşılığı'],
        'count_gain' => ['649', 'Kasa sayım fazlası'],
        'count_loss' => ['659', 'Kasa sayım eksiği'],
        'income_default' => ['602', 'Eşlenmemiş gelir kategorisi'],
        'expense_default' => ['770', 'Eşlenmemiş gider kategorisi'],
    ];

    /** Kategori koduna göre varsayılan hesap. */
    public const CATEGORY_DEFAULTS = [
        'student_payment' => '340', 'book_sale' => '600', 'private_lesson' => '600', 'other_income' => '602',
        'advertising' => '760', 'book_purchase' => '153', 'pos_commission' => '653',
    ];

    public function ensureDefaults(int $branchId): void
    {
        // Önbellek YOK: geri alınan bir transaction varsayılanları da geri alabilir (tek sorgu, ucuz).
        $existing = DB::table('ledger_accounts')->where('branch_id', $branchId)->pluck('code')->flip();
        $now = now();
        foreach (self::DEFAULTS as [$code, $name, $type, $side]) {
            if (! isset($existing[$code])) {
                DB::table('ledger_accounts')->insertOrIgnore([
                    'branch_id' => $branchId, 'code' => $code, 'name' => $name, 'type' => $type, 'normal_side' => $side,
                    'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public static function forgetCache(): void
    {
        self::$mapCache = [];
    }

    private static array $mapCache = [];

    private function mappings(int $branchId): array
    {
        return self::$mapCache[$branchId] ??= DB::table('ledger_mappings')->where('branch_id', $branchId)->get(['source_type', 'source_key', 'ledger_code'])
            ->mapWithKeys(fn ($r) => ["{$r->source_type}:{$r->source_key}" => $r->ledger_code])->all();
    }

    public function roleCode(int $branchId, string $role): string
    {
        return $this->mappings($branchId)["role:{$role}"] ?? self::ROLES[$role][0];
    }

    public function accountCode(int $branchId, int $financeAccountId, ?string $kind = null): string
    {
        $mapped = $this->mappings($branchId)["finance_account:{$financeAccountId}"] ?? null;
        if ($mapped) {
            return $mapped;
        }
        $kind ??= DB::table('finance_accounts')->where('id', $financeAccountId)->value('kind');

        return $this->roleCode($branchId, in_array($kind, ['cash', 'bank', 'pos'], true) ? $kind : 'cash');
    }

    public function categoryCode(int $branchId, int $categoryId, ?string $direction = null, ?string $code = null): string
    {
        $mapped = $this->mappings($branchId)["finance_category:{$categoryId}"] ?? null;
        if ($mapped) {
            return $mapped;
        }
        if ($direction === null || $code === null) {
            $cat = DB::table('finance_categories')->where('id', $categoryId)->first(['direction', 'code']);
            $direction ??= $cat?->direction;
            $code ??= $cat?->code;
        }
        if ($code && isset(self::CATEGORY_DEFAULTS[$code])) {
            return self::CATEGORY_DEFAULTS[$code];
        }

        return $this->roleCode($branchId, $direction === 'income' ? 'income_default' : 'expense_default');
    }

    /** @return array<string, array{code:string, name:string, type:string, normal_side:string, is_active:bool}> */
    public function accounts(int $branchId): array
    {
        $this->ensureDefaults($branchId);

        return DB::table('ledger_accounts')->where('branch_id', $branchId)->orderBy('code')->get()
            ->mapWithKeys(fn ($r) => [$r->code => ['code' => $r->code, 'name' => $r->name, 'type' => $r->type, 'normal_side' => $r->normal_side, 'is_active' => (bool) $r->is_active, 'is_system' => (bool) $r->is_system, 'id' => $r->id]])->all();
    }

    /** Eşleme tablosu (ekran): tüm hesaplar, kategoriler ve roller, etkin kodlarıyla. */
    public function mappingOverview(int $branchId): array
    {
        $this->ensureDefaults($branchId);
        $map = $this->mappings($branchId);

        return [
            'accounts' => FinanceAccount::query()->withTrashed()->where('branch_id', $branchId)->orderBy('kind')->orderBy('name')->get()
                ->map(fn (FinanceAccount $a) => ['source_type' => 'finance_account', 'source_key' => (string) $a->id, 'label' => $a->name, 'group' => FinanceAccount::KINDS[$a->kind] ?? $a->kind,
                    'code' => $this->accountCode($branchId, $a->id, $a->kind), 'custom' => isset($map["finance_account:{$a->id}"]), 'inactive' => ! $a->is_active || $a->trashed()])->values()->all(),
            'categories' => FinanceCategory::query()->where('branch_id', $branchId)->orderBy('direction')->orderBy('name')->get()
                ->map(fn (FinanceCategory $c) => ['source_type' => 'finance_category', 'source_key' => (string) $c->id, 'label' => $c->name, 'group' => $c->direction === 'income' ? 'Gelir' : 'Gider',
                    'code' => $this->categoryCode($branchId, $c->id, $c->direction, $c->code), 'custom' => isset($map["finance_category:{$c->id}"]), 'inactive' => false])->values()->all(),
            'roles' => collect(self::ROLES)->map(fn ($def, $key) => ['source_type' => 'role', 'source_key' => $key, 'label' => $def[1], 'group' => 'Sistem',
                'code' => $this->roleCode($branchId, $key), 'custom' => isset($map["role:{$key}"]), 'inactive' => false])->values()->all(),
        ];
    }

    public function setMapping(int $branchId, string $sourceType, string $sourceKey, ?string $code): void
    {
        if (! in_array($sourceType, ['finance_account', 'finance_category', 'role'], true)) {
            throw new BusinessRuleException('Geçersiz eşleme türü.', 'invalid_mapping');
        }
        if ($sourceType === 'role' && ! isset(self::ROLES[$sourceKey])) {
            throw new BusinessRuleException('Geçersiz sistem rolü.', 'invalid_mapping');
        }
        if ($code !== null) {
            $acc = $this->accounts($branchId)[$code] ?? null;
            if (! $acc || ! $acc['is_active']) {
                throw new BusinessRuleException("{$code} kodlu aktif hesap bulunamadı.", 'ledger_code_missing');
            }
        }
        $before = DB::table('ledger_mappings')->where(['branch_id' => $branchId, 'source_type' => $sourceType, 'source_key' => $sourceKey])->value('ledger_code');
        if ($code === null) {
            LedgerMapping::query()->where(['branch_id' => $branchId, 'source_type' => $sourceType, 'source_key' => $sourceKey])->delete();
        } else {
            LedgerMapping::query()->updateOrCreate(['branch_id' => $branchId, 'source_type' => $sourceType, 'source_key' => $sourceKey], ['ledger_code' => $code]);
        }
        unset(self::$mapCache[$branchId]);
        FinanceAudit::log('accounting.mapping_updated', sprintf('%s/%s hesap eşlemesini %s → %s olarak değiştirdi (yeni fişlere uygulanır).', $sourceType, $sourceKey, $before ?? 'varsayılan', $code ?? 'varsayılan'));
    }

    /** @param array{code:string, name:string, type:string} $data */
    public function saveAccount(int $branchId, array $data, ?LedgerAccount $account = null): LedgerAccount
    {
        $this->ensureDefaults($branchId);
        if (! isset(LedgerAccount::TYPES[$data['type']])) {
            throw new BusinessRuleException('Geçersiz hesap türü.', 'invalid_type');
        }
        if (! preg_match('/^\d{3}(\.\d{1,3}){0,3}$/', $data['code'])) {
            throw new BusinessRuleException('Hesap kodu 3 haneli ana hesap ya da 100.01 biçiminde alt hesap olmalı.', 'invalid_code');
        }
        $side = in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit';
        if (in_array(substr($data['code'], 0, 3), ['610', '611', '612'], true)) {
            $side = 'debit';
        }
        if ($account) {
            $used = DB::table('journal_lines')->where('branch_id', $branchId)->where('ledger_code', $account->code)->exists();
            if ($used && $account->code !== $data['code']) {
                throw new BusinessRuleException('Fişlerde kullanılmış hesabın kodu değiştirilemez; pasife alıp yeni hesap açın.', 'code_in_use');
            }
            $account->fill(['code' => $data['code'], 'name' => trim($data['name']), 'type' => $data['type'], 'normal_side' => $side]);
            if (array_key_exists('is_active', $data)) {
                $account->is_active = (bool) $data['is_active'];
            }
            $account->save();
            FinanceAudit::log('accounting.account_updated', "{$account->code} {$account->name} hesabını güncelledi.");
        } else {
            if (DB::table('ledger_accounts')->where('branch_id', $branchId)->where('code', $data['code'])->exists()) {
                throw new BusinessRuleException('Bu kodla bir hesap zaten var.', 'code_exists');
            }
            $account = LedgerAccount::query()->create(['branch_id' => $branchId, 'code' => $data['code'], 'name' => trim($data['name']), 'type' => $data['type'], 'normal_side' => $side, 'is_active' => true, 'is_system' => false]);
            FinanceAudit::log('accounting.account_created', "{$account->code} {$account->name} hesabını açtı.");
        }

        return $account;
    }
}
