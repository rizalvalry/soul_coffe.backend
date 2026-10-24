<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Enums\WasteReason;
use App\Models\AuditLog;
use App\Models\CentralKitchen;
use App\Models\PackagingWaste;
use App\Models\Product;
use App\Models\PromotionalUse;
use App\Models\RawMaterial;
use App\Models\User;
use App\Models\WasteClassification;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WasteTrackingService
{
    public function __construct(private readonly StockLedgerService $ledger) {}

    public function recordPromotion(User $actor, string $requestKey, int $kitchenId, int $productId,
        int $qty, string $note): PromotionalUse
    {
        $this->authorize($actor, 'create');
        $this->assertPromotionActor($actor, $kitchenId);
        Validator::make(compact('requestKey', 'kitchenId', 'productId', 'qty', 'note'), [
            'requestKey' => ['required', 'uuid'], 'kitchenId' => ['required', 'exists:central_kitchens,id'],
            'productId' => ['required', 'exists:products,id'], 'qty' => ['required', 'integer', 'between:1,9999'],
            'note' => ['required', 'string', 'max:500'],
        ])->validate();
        Product::assertOperationalIds([$productId]);
        $hash = hash('sha256', json_encode([$actor->id, $kitchenId, $productId, $qty, $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $requestKey, $kitchenId, $productId, $qty, $note, $hash): PromotionalUse {
            $this->ledger->lockProductCostRows([$productId]);
            CentralKitchen::query()->whereKey($kitchenId)->lockForUpdate()->firstOrFail();
            if ($existing = PromotionalUse::query()->where('request_key', $requestKey)->first()) {
                $this->assertSameRequest($existing->payload_hash, $hash);

                return $existing;
            }
            $stock = $this->ledger->lockAndProject(StockLedgerService::KITCHEN, $kitchenId, [$productId]);
            if (($stock[$productId] ?? 0) < $qty) {
                throw ValidationException::withMessages(['qty' => 'Stok showcase belum cukup untuk jumlah ini ya.']);
            }
            $use = PromotionalUse::query()->create([
                'request_key' => $requestKey, 'payload_hash' => $hash, 'kitchen_id' => $kitchenId,
                'product_id' => $productId, 'qty' => $qty, 'note' => $note, 'actor_id' => $actor->id,
            ]);
            $movement = $this->ledger->post(StockLedgerService::KITCHEN, $kitchenId, $productId,
                MovementType::WASTE_OUT, $qty, $actor->id, $kitchenId, 'promotional_use', $use->id,
                note: $note);
            $use->update(['stock_ledger_id' => $movement->id]);
            AuditLog::recordAction($actor, 'promotional_use.recorded', $use, null,
                ['kitchen_id' => $kitchenId, 'product_id' => $productId, 'qty' => $qty,
                    'note' => $note, 'stock_ledger_id' => $movement->id]);

            return $use;
        });
    }

    public function recordPackaging(User $actor, string $requestKey, string $date, int $kitchenId,
        int $rawMaterialId, int $qty, ?int $unitCostMinor, ?string $note): PackagingWaste
    {
        $this->authorizeFinance($actor, 'create');
        Validator::make(compact('requestKey', 'date', 'kitchenId', 'rawMaterialId', 'qty', 'unitCostMinor', 'note'), [
            'requestKey' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'kitchenId' => ['required', 'exists:central_kitchens,id'],
            'rawMaterialId' => ['required', 'exists:raw_materials,id'],
            'qty' => ['required', 'integer', 'between:1,9999'],
            'unitCostMinor' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ])->validate();
        $material = RawMaterial::query()->where('is_active', true)->findOrFail($rawMaterialId);
        if (! $material->is_packaging_cup) {
            throw ValidationException::withMessages(['rawMaterialId' => 'Pilih bahan baku cup kemasan ya.']);
        }
        $hash = hash('sha256', json_encode([$actor->id, $date, $kitchenId, $rawMaterialId,
            $qty, $unitCostMinor, $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $requestKey, $date, $kitchenId, $rawMaterialId, $qty,
            $unitCostMinor, $note, $hash): PackagingWaste {
            CentralKitchen::query()->whereKey($kitchenId)->lockForUpdate()->firstOrFail();
            if ($existing = PackagingWaste::query()->where('request_key', $requestKey)->first()) {
                $this->assertSameRequest($existing->payload_hash, $hash);

                return $existing;
            }
            // The same writer mutex also guards receipts and recipe consumption. Its state is
            // a current read, independent of an earlier MySQL REPEATABLE READ snapshot.
            $state = $this->ledger->lockRawMaterialState($kitchenId, $rawMaterialId);
            $tracked = $state['tracked'];
            $cost = $qty * (int) $unitCostMinor;
            if (! $tracked && $cost <= 0) {
                throw ValidationException::withMessages(['unitCostMinor' => 'Isi biaya per cup kemasan dulu ya.']);
            }
            $waste = PackagingWaste::query()->create([
                'request_key' => $requestKey, 'payload_hash' => $hash, 'operating_date' => $date,
                'kitchen_id' => $kitchenId, 'raw_material_id' => $rawMaterialId,
                'qty' => $qty, 'cost_minor' => $cost, 'note' => $note, 'actor_id' => $actor->id,
            ]);
            if ($tracked) {
                if ($state['quantity'] < $qty) {
                    throw ValidationException::withMessages(['qty' => 'Stok cup kemasan belum cukup ya.']);
                }
                if ($state['value_minor'] <= 0) {
                    throw ValidationException::withMessages(['rawMaterialId' => 'Nilai stok cup kemasan belum ada. Minta Finance cek biaya stok dulu ya.']);
                }
                $movement = $this->ledger->post(StockLedgerService::RAW_MATERIAL_STORE, $kitchenId,
                    null, MovementType::WASTE_OUT, $qty, $actor->id, $kitchenId,
                    'packaging_waste', $waste->id, rawMaterialId: $rawMaterialId,
                    note: $note,
                    occurredAt: $date === today()->toDateString() ? now() : Carbon::parse($date)->endOfDay());
                $cost = (int) $movement->cost_minor;
                $waste->update(['stock_ledger_id' => $movement->id, 'cost_minor' => $cost]);
            }
            AuditLog::recordAction($actor, 'packaging_waste.recorded', $waste, null,
                ['operating_date' => $date, 'kitchen_id' => $kitchenId,
                    'raw_material_id' => $rawMaterialId, 'qty' => $qty, 'cost_minor' => $cost,
                    'stock_ledger_id' => $waste->stock_ledger_id]);

            return $waste;
        });
    }

    public function correctReason(User $actor, string $requestKey, int $classificationId,
        WasteReason $reason, string $note): WasteClassification
    {
        $this->authorizeFinance($actor, 'edit');
        Validator::make(compact('requestKey', 'note'), [
            'requestKey' => ['required', 'uuid'], 'note' => ['required', 'string', 'max:500'],
        ])->validate();
        $hash = hash('sha256', json_encode([$actor->id, $classificationId, $reason->value, $note], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $requestKey, $classificationId, $reason, $note, $hash): WasteClassification {
            $record = WasteClassification::query()->whereKey($classificationId)->lockForUpdate()->firstOrFail();
            if ($existing = DB::table('waste_reason_corrections')->where('request_key', $requestKey)->first()) {
                $this->assertSameRequest($existing->payload_hash, $hash);

                return $record;
            }
            if ($record->reason_code === $reason) {
                throw ValidationException::withMessages(['reason' => 'Alasannya masih sama ya.']);
            }
            $before = ['reason_code' => $record->reason_code->value];
            $record->update(['reason_code' => $reason]);
            DB::table('waste_reason_corrections')->insert([
                'request_key' => $requestKey, 'classification_id' => $classificationId,
                'payload_hash' => $hash, 'created_at' => now(),
            ]);
            AuditLog::recordAction($actor, 'waste_reason.corrected', $record, $before,
                ['reason_code' => $reason->value, 'note' => $note]);

            return $record;
        });
    }

    /** Search the full history instead of limiting Finance to the newest waste rows. */
    public function classificationOptions(User $actor, string $search): array
    {
        $this->authorizeFinance($actor, 'edit');

        return WasteClassification::query()->with(['movement.product', 'movement.rawMaterial'])
            ->when(ctype_digit($search), fn (Builder $query): Builder => $query
                ->where('stock_ledger_id', (int) $search))
            ->when($search !== '' && ! ctype_digit($search), function (Builder $query) use ($search): void {
                $query->whereHas('movement', function (Builder $movement) use ($search): void {
                    $movement->whereHas('product', fn (Builder $product): Builder => $product
                        ->where('name', 'like', '%'.$search.'%'))
                        ->orWhereHas('rawMaterial', fn (Builder $raw): Builder => $raw
                            ->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->latest()->limit(50)->get()
            ->mapWithKeys(fn (WasteClassification $row): array => [$row->id => $this->classificationLabelValue($row)])
            ->all();
    }

    public function classificationLabel(User $actor, int $classificationId): ?string
    {
        $this->authorizeFinance($actor, 'edit');
        $row = WasteClassification::query()->with(['movement.product', 'movement.rawMaterial'])
            ->find($classificationId);

        return $row ? $this->classificationLabelValue($row) : null;
    }

    private function classificationLabelValue(WasteClassification $row): string
    {
        return '#'.$row->stock_ledger_id.' · '.($row->movement->product?->name
            ?? $row->movement->rawMaterial?->name ?? 'Bahan baku').' · '.$row->reason_code->label();
    }

    private function assertPromotionActor(User $actor, int $kitchenId): void
    {
        abort_unless(in_array($actor->role, [Role::BARISTA, Role::FINANCE], true), 403);
        abort_unless($actor->role !== Role::BARISTA || (int) $actor->kitchen_id === $kitchenId, 403);
    }

    private function authorize(User $actor, string $ability): void
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::WASTE_MANAGEMENT, $ability), 403);
    }

    private function authorizeFinance(User $actor, string $ability): void
    {
        $this->authorize($actor, $ability);
        abort_unless($actor->role === Role::FINANCE, 403);
    }

    private function assertSameRequest(string $actual, string $expected): void
    {
        if (! hash_equals($actual, $expected)) {
            throw ValidationException::withMessages(['requestKey' => 'Kunci permintaan sudah dipakai untuk data lain ya.']);
        }
    }
}
