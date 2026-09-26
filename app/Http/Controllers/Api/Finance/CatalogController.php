<?php

namespace App\Http\Controllers\Api\Finance;

use App\Services\Finance\FinanceAudit;
use App\Exceptions\BusinessRuleException;
use App\Models\AcademicTerm;
use App\Models\EducationPackage;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Finance\InventoryService;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Eğitim paketleri ve kitap/materyal envanteri. */
class CatalogController extends FinanceController
{
    // ------------------------------------------------------------------ eğitim paketleri

    public function packages(Request $request): JsonResponse
    {
        $counts = DB::table('enrollments')->where('branch_id', $this->branchId())->whereNull('deleted_at')->whereNotNull('education_package_id')
            ->groupBy('education_package_id')->selectRaw('education_package_id, COUNT(*) AS c')->pluck('c', 'education_package_id');

        $rows = EducationPackage::query()->with(['program:id,name'])
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->when($request->integer('program_id'), fn ($q, $v) => $q->where('program_id', $v))
            ->when($request->integer('term_id'), fn ($q, $v) => $q->where('academic_term_id', $v))
            ->orderByDesc('is_active')->orderBy('name')->get();
        $terms = AcademicTerm::query()->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn (EducationPackage $p) => [
            'id' => $p->id, 'name' => $p->name, 'type' => $p->type ?? 'course', 'program_id' => $p->program_id, 'program' => $p->program?->name,
            'academic_term_id' => $p->academic_term_id, 'term' => $terms[$p->academic_term_id] ?? null,
            'list_price' => (string) $p->list_price, 'default_installments' => (int) $p->default_installments, 'includes' => $p->includes,
            'has_coaching' => (bool) $p->has_coaching,
            'is_active' => $p->is_active, 'enrollment_count' => (int) ($counts[$p->id] ?? 0),
            'monthly' => $p->default_installments > 0 ? bcdiv((string) $p->list_price, (string) $p->default_installments, 2) : null,
        ])]);
    }

    public function storePackage(Request $request): JsonResponse
    {
        $data = $this->packageData($request);
        $package = EducationPackage::query()->create($data);
        FinanceAudit::log('education_package.created', sprintf('"%s" eğitim paketini %s TL liste fiyatıyla oluşturdu.', $package->name, Money::format($package->list_price)), $package);

        return response()->json(['message' => 'Paket oluşturuldu.', 'id' => $package->id], 201);
    }

    public function updatePackage(Request $request, EducationPackage $package): JsonResponse
    {
        $package->fill($this->packageData($request))->save();
        $diff = Audit::diff($package);
        if ($diff['after']) {
            FinanceAudit::log('education_package.updated', "\"{$package->name}\" eğitim paketini güncelledi. Mevcut kayıtların ücreti değişmez.", $package, $diff);
        }

        return $this->ok('Paket güncellendi. Mevcut kayıtların ücreti değişmez.');
    }

    public function destroyPackage(EducationPackage $package): JsonResponse
    {
        if (DB::table('enrollments')->where('education_package_id', $package->id)->whereNull('deleted_at')->exists()) {
            $package->forceFill(['is_active' => false])->save();
            FinanceAudit::log('education_package.deactivated', "\"{$package->name}\" paketini pasife aldı (kayıtlarda kullanıldığı için silinmedi).", $package);

            return $this->ok('Paket kayıtlarda kullanıldığı için pasife alındı.');
        }
        $package->delete();
        FinanceAudit::log('education_package.deleted', "\"{$package->name}\" eğitim paketini sildi.", $package);

        return $this->ok('Paket silindi.');
    }

    private function packageData(Request $request): array
    {
        $data = $this->validateTr($request, [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'type' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(EducationPackage::TYPES))],
            'program_id' => ['nullable', 'integer'],
            'academic_term_id' => ['nullable', 'integer'],
            'list_price' => ['required', 'string', self::MONEY],
            'default_installments' => ['required', 'integer', 'min:1', 'max:36'],
            'includes' => ['nullable', 'string', 'max:2000'],
            'has_coaching' => ['boolean'],
            'is_active' => ['boolean'],
        ], ['list_price.regex' => 'Fiyatı 45000 ya da 45000,50 biçiminde girin.']);
        $data['type'] = $data['type'] ?? 'course';

        if (! empty($data['program_id'])) {
            \App\Models\Program::query()->findOrFail($data['program_id']);
        }
        if (! empty($data['academic_term_id'])) {
            AcademicTerm::query()->findOrFail($data['academic_term_id']);
        }
        $data['list_price'] = Money::of($data['list_price']);

        return $data;
    }

    // ------------------------------------------------------------------ envanter

    public function products(Request $request): JsonResponse
    {
        $query = Product::query()->select('products.*')
            ->addSelect(['delivered_total' => DB::table('stock_movements')->selectRaw('COALESCE(-SUM(quantity), 0)')->whereColumn('product_id', 'products.id')->whereIn('kind', ['delivery', 'return'])])
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true));

        if ($q = trim((string) $request->query('q'))) {
            $query->where(fn (Builder $w) => $w->where('name', 'like', '%'.$q.'%')->orWhere('publisher', 'like', '%'.$q.'%')->orWhere('barcode', $q));
        }
        if ($request->boolean('low_stock')) {
            $query->where('min_stock', '>', 0)->whereColumn('stock', '<=', 'min_stock');
        }
        $this->applySort($query, $request, ['name' => 'name', 'stock' => 'stock', 'sale_price' => 'sale_price'], 'name');
        $low = Product::query()->where('is_active', true)->where('min_stock', '>', 0)->whereColumn('stock', '<=', 'min_stock')->count();
        $subjects = Subject::query()->pluck('name', 'id');

        return $this->paginated($query->paginate($this->perPage($request, 50)), fn (Product $p) => [
            'id' => $p->id, 'name' => $p->name, 'publisher' => $p->publisher, 'barcode' => $p->barcode, 'subject_id' => $p->subject_id, 'subject' => $subjects[$p->subject_id] ?? null,
            'purchase_price' => (string) $p->purchase_price, 'sale_price' => (string) $p->sale_price, 'stock' => (int) $p->stock, 'min_stock' => (int) $p->min_stock,
            'is_active' => $p->is_active, 'low_stock' => $p->min_stock > 0 && $p->stock <= $p->min_stock, 'delivered_total' => (int) $p->delivered_total,
            'stock_value' => bcmul((string) $p->purchase_price, (string) max(0, (int) $p->stock), 2),
        ], ['low_stock_count' => $low, 'subjects' => Subject::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $this->productData($request);
        $product = Product::query()->create($data);
        FinanceAudit::log('inventory.product_created', "\"{$product->name}\" ürününü envantere ekledi.", $product);

        return response()->json(['message' => 'Ürün eklendi. Stok girişini "Stok girişi" ile yapın.', 'id' => $product->id], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $product->fill($this->productData($request, $product))->save();
        $diff = Audit::diff($product);
        if ($diff['after']) {
            FinanceAudit::log('inventory.product_updated', "\"{$product->name}\" ürün bilgilerini güncelledi.", $product, $diff);
        }

        return $this->ok('Ürün güncellendi.');
    }

    public function destroyProduct(Product $product): JsonResponse
    {
        if (StockMovement::query()->where('product_id', $product->id)->exists()) {
            $product->forceFill(['is_active' => false])->save();
            FinanceAudit::log('inventory.product_deactivated', "\"{$product->name}\" ürününü pasife aldı (hareket geçmişi korunur).", $product);

            return $this->ok('Ürünün hareket geçmişi olduğu için pasife alındı.');
        }
        $product->delete();
        FinanceAudit::log('inventory.product_deleted', "\"{$product->name}\" ürününü sildi.", $product);

        return $this->ok('Ürün silindi.');
    }

    public function movements(Request $request): JsonResponse
    {
        $query = StockMovement::query()->with(['product:id,name', 'student:id,full_name,student_no'])->orderByDesc('created_at')->orderByDesc('id');
        foreach (['product_id', 'student_id'] as $param) {
            if ($v = $request->integer($param)) {
                $query->where($param, $v);
            }
        }
        if (($kind = $request->query('kind')) && isset(InventoryService::KINDS[$kind])) {
            $query->where('kind', $kind);
        }
        $this->applyDateRange($query, $request, 'created_at');
        $users = DB::table('users')->pluck('name', 'id');

        return $this->paginated($query->paginate($this->perPage($request, 30)), fn (StockMovement $m) => [
            'id' => $m->id, 'kind' => $m->kind, 'kind_label' => InventoryService::KINDS[$m->kind] ?? $m->kind, 'quantity' => (int) $m->quantity,
            'product' => $m->product ? ['id' => $m->product->id, 'name' => $m->product->name] : null,
            'student' => $m->student ? ['id' => $m->student->id, 'full_name' => $m->student->full_name, 'student_no' => $m->student->student_no] : null,
            'unit_price' => $m->unit_price !== null ? (string) $m->unit_price : null, 'finance_entry_id' => $m->finance_entry_id, 'note' => $m->note,
            'created_by' => $users[$m->created_by] ?? null, 'created_at' => $m->created_at?->toAtomString(),
        ]);
    }

    public function stockIn(Request $request, Product $product, InventoryService $inventory): JsonResponse
    {
        $data = $this->validateTr($request, [
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price' => ['nullable', 'string', self::MONEY],
            'note' => ['nullable', 'string', 'max:300'],
            'expense_account_id' => ['nullable', 'integer'],
        ]);
        $inventory->stockIn($product, $data);

        return $this->ok('Stok girişi kaydedildi.');
    }

    public function deliver(Request $request, Product $product, InventoryService $inventory): JsonResponse
    {
        $data = $this->validateTr($request, [
            'student_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'note' => ['nullable', 'string', 'max:300'],
            'charge' => ['boolean'],
            'unit_price' => ['nullable', 'string', self::MONEY],
            'income_account_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['charge']) && ! $request->user()->can('expenses.manage')) {
            throw new BusinessRuleException('Satış geliri kaydetmek için gelir/gider yetkisi gerekir.', 'forbidden', [], 403);
        }
        $student = Student::query()->findOrFail($data['student_id']);
        $inventory->deliver($product, $student, $data);

        return $this->ok("{$student->full_name} öğrencisine teslim kaydedildi.");
    }

    public function returnItem(Request $request, Product $product, InventoryService $inventory): JsonResponse
    {
        $data = $this->validateTr($request, ['student_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:1000'], 'note' => ['nullable', 'string', 'max:300']]);
        $student = Student::query()->findOrFail($data['student_id']);
        $inventory->returnFromStudent($product, $student, (int) $data['quantity'], $data['note'] ?? null);

        return $this->ok('İade kaydedildi; stok artırıldı.');
    }

    public function adjustStock(Request $request, Product $product, InventoryService $inventory): JsonResponse
    {
        $data = $this->validateTr($request, ['counted' => ['required', 'integer', 'min:0', 'max:1000000'], 'note' => ['required', 'string', 'min:3', 'max:300']]);
        $inventory->adjust($product, (int) $data['counted'], $data['note']);

        return $this->ok('Stok sayımı kaydedildi.');
    }

    private function productData(Request $request, ?Product $product = null): array
    {
        $data = $this->validateTr($request, [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'publisher' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:40', Rule::unique('products', 'barcode')->where('branch_id', $this->branchId())->ignore($product?->id)],
            'subject_id' => ['nullable', 'integer'],
            'purchase_price' => ['nullable', 'string', self::MONEY],
            'sale_price' => ['nullable', 'string', self::MONEY],
            'min_stock' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['boolean'],
        ], ['barcode.unique' => 'Bu barkod başka bir üründe kayıtlı.']);

        if (! empty($data['subject_id'])) {
            Subject::query()->findOrFail($data['subject_id']);
        }
        $data['purchase_price'] = Money::of($data['purchase_price'] ?? '0');
        $data['sale_price'] = Money::of($data['sale_price'] ?? '0');
        $data['min_stock'] = (int) ($data['min_stock'] ?? 0);
        $data['barcode'] = ($data['barcode'] ?? '') !== '' ? $data['barcode'] : null;

        return $data;
    }
}
