<?php

namespace App\Services;

use App\Enums\KitchenRejectReason;
use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\KitchenReject;
use App\Models\KitchenRejectLimit;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class KitchenRejectService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly MediaService $media,
        private readonly EventPublisher $events,
    ) {}

    public function record(User $actor, int $kitchenId, array $lines, string $reasonCode,
        ?string $note, UploadedFile $photo, Carbon $photoTakenAt): KitchenReject
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::KITCHEN_REJECT, 'create'), 403);
        $this->assertKitchenAccess($actor, $kitchenId);

        $newPhotoPath = null;
        try {
            return DB::transaction(function () use ($actor, $kitchenId, $lines, $reasonCode, $note, $photo, $photoTakenAt, &$newPhotoPath): KitchenReject {
                $ids = array_map(fn (array $line): int => (int) $line['product_id'], $lines);
                Product::assertOperationalIds($ids);
                // Stock writers lock product cost rows first. Keep that order before taking the
                // kitchen mutex, which serializes daily threshold calculations across products.
                $this->ledger->lockProductCostRows($ids);
                DB::table('central_kitchens')->where('id', $kitchenId)->lockForUpdate()->firstOrFail();
                $products = Product::query()->whereIn('id', $ids)->get()->keyBy('id');
                $stock = $this->ledger->lockAndProject(StockLedgerService::KITCHEN, $kitchenId, $ids);
                foreach ($lines as $line) {
                    $id = (int) $line['product_id'];
                    if (($stock[$id] ?? 0) < (int) $line['qty']) {
                        throw ValidationException::withMessages(['lines' => 'Stok showcase tidak cukup untuk '.$products[$id]->name.'.']);
                    }
                }

                $date = today()->toDateString();
                $needsReview = false;
                foreach ($lines as $line) {
                    $id = (int) $line['product_id'];
                    $prior = (int) DB::table('kitchen_reject_lines as l')
                        ->join('kitchen_rejects as r', 'r.id', '=', 'l.kitchen_reject_id')
                        ->where('r.kitchen_id', $kitchenId)->where('r.operating_date', $date)
                        ->where('l.product_id', $id)->sum('l.qty');
                    $limit = (int) (KitchenRejectLimit::query()->where('kitchen_id', $kitchenId)
                        ->where('product_id', $id)->value('daily_limit') ?? 5);
                    $needsReview = $needsReview || $prior + (int) $line['qty'] > $limit;
                }

                $journals = app(JournalService::class);
                if ($journals->isAccountingDate(today()->toDateString())) {
                    $journals->assertOpenDate(today()->toDateString());
                }
                $media = $this->media->storeKitchenRejectPhoto($photo, $photoTakenAt, $actor);
                if ($media->wasRecentlyCreated) {
                    $newPhotoPath = $media->path;
                }
                $reject = KitchenReject::query()->create([
                    'kitchen_id' => $kitchenId, 'actor_id' => $actor->id, 'photo_media_id' => $media->id,
                    'operating_date' => $date, 'reason_code' => $reasonCode, 'note' => $note,
                    'status' => $needsReview ? 'NEEDS_REVIEW' : 'RECORDED',
                ]);
                $detail = [];
                foreach ($lines as $line) {
                    $id = (int) $line['product_id'];
                    $qty = (int) $line['qty'];
                    $movement = $this->ledger->post(StockLedgerService::KITCHEN, $kitchenId,
                        $id, MovementType::WASTE_OUT, $qty, $actor->id, $kitchenId,
                        refType: 'kitchen_reject', refId: $reject->id);
                    $reject->lines()->create(['product_id' => $id, 'qty' => $qty,
                        'cost_minor' => (int) $movement->cost_minor]);
                    $detail[] = $products[$id]->name.' '.$qty.' cup';
                }
                AuditLog::recordAction($actor, 'kitchen_reject.recorded', $reject, null,
                    ['kitchen_id' => $kitchenId, 'lines' => $lines, 'reason_code' => $reasonCode,
                        'status' => $reject->status, 'photo_media_id' => $media->id]);
                $body = implode(', ', $detail).' · '.KitchenRejectReason::labelFor($reasonCode).' · '.$actor->name.' · '.$reject->kitchen->name;
                if ($needsReview) {
                    $body .= ' · melewati batas harian';
                }
                $recipients = User::query()->where('role', 'FINANCE')->where('is_active', true)->pluck('id')->all();
                $recipients = array_values(array_unique([...$recipients, ...User::managementIds()]));
                $this->events->publish('KitchenRejectRecorded', 'Reject Dapur', $body,
                    array_map(fn (int $id): string => 'user.'.$id, $recipients), $recipients,
                    status: $reject->status, context: ['source_id' => $reject->id]);

                return $reject->load(['lines.product', 'kitchen', 'actor', 'photo']);
            });
        } catch (\Throwable $error) {
            if ($newPhotoPath !== null) {
                Storage::disk('public')->delete($newPhotoPath);
            }
            throw $error;
        }
    }

    public function review(KitchenReject $reject, User $actor): KitchenReject
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::KITCHEN_REJECT, 'edit'), 403);
        $this->assertKitchenAccess($actor, (int) $reject->kitchen_id);

        return DB::transaction(function () use ($reject, $actor): KitchenReject {
            $locked = KitchenReject::query()->whereKey($reject->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'CORRECTED') {
                throw ValidationException::withMessages(['status' => 'Reject ini sudah dikoreksi ya.']);
            }
            if ($locked->status !== 'REVIEWED') {
                $before = ['status' => $locked->status];
                $locked->update(['status' => 'REVIEWED', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
                AuditLog::recordAction($actor, 'kitchen_reject.reviewed', $locked, $before,
                    ['status' => 'REVIEWED']);
            }

            return $locked->load(['lines.product', 'kitchen', 'actor', 'photo']);
        });
    }

    public function correct(KitchenReject $reject, User $actor, string $reason): KitchenReject
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::KITCHEN_REJECT, 'edit'), 403);
        $this->assertKitchenAccess($actor, (int) $reject->kitchen_id);

        return DB::transaction(function () use ($reject, $actor, $reason): KitchenReject {
            $productIds = $reject->lines()->pluck('product_id')->all();
            $this->ledger->lockProductCostRows($productIds);
            $locked = KitchenReject::query()->whereKey($reject->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'CORRECTED') {
                throw ValidationException::withMessages(['status' => 'Reject ini sudah dikoreksi ya.']);
            }
            $before = ['status' => $locked->status];
            foreach ($locked->lines()->orderBy('product_id')->get() as $line) {
                $original = StockLedger::query()->where('ref_type', 'kitchen_reject')
                    ->where('ref_id', $locked->id)->where('product_id', $line->product_id)->sole();
                $resolved = DB::table('stock_cost_resolutions')->where('stock_ledger_id', $original->id)
                    ->value('resolved_cost_minor');
                $pending = $original->cost_pending && $resolved === null;
                $this->ledger->post(StockLedgerService::KITCHEN, (int) $locked->kitchen_id,
                    (int) $line->product_id, MovementType::ADJUSTMENT, (int) $line->qty,
                    $actor->id, (int) $locked->kitchen_id, refType: 'kitchen_reject_correction',
                    refId: $locked->id, costMinor: (int) $original->cost_minor + (int) $resolved,
                    note: $reason, costPending: $pending,
                    costPendingQty: $pending ? (int) $original->cost_pending_qty : 0);
            }
            $locked->update(['status' => 'CORRECTED', 'reviewed_by' => $actor->id,
                'reviewed_at' => now(), 'correction_reason' => $reason]);
            AuditLog::recordAction($actor, 'kitchen_reject.corrected', $locked, $before,
                ['status' => 'CORRECTED', 'reason' => $reason]);

            return $locked->load(['lines.product', 'kitchen', 'actor', 'photo']);
        });
    }

    public function setLimit(User $actor, int $kitchenId, int $productId, int $dailyLimit): KitchenRejectLimit
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::KITCHEN_REJECT, 'edit'), 403);
        $this->assertKitchenAccess($actor, $kitchenId);
        if ($dailyLimit < 0 || $dailyLimit > 9999) {
            throw ValidationException::withMessages(['daily_limit' => 'Batas harian harus 0–9.999 cups ya.']);
        }
        Product::assertOperationalIds([$productId]);

        return DB::transaction(function () use ($actor, $kitchenId, $productId, $dailyLimit): KitchenRejectLimit {
            DB::table('central_kitchens')->where('id', $kitchenId)->where('is_active', true)
                ->lockForUpdate()->firstOrFail();
            $limit = KitchenRejectLimit::query()->firstOrCreate(
                ['kitchen_id' => $kitchenId, 'product_id' => $productId], ['daily_limit' => 5]);
            $before = ['daily_limit' => $limit->daily_limit];
            $limit->update(['daily_limit' => $dailyLimit]);
            AuditLog::recordAction($actor, 'kitchen_reject.limit_changed', $limit, $before,
                ['daily_limit' => $dailyLimit, 'kitchen_id' => $kitchenId, 'product_id' => $productId]);

            return $limit;
        });
    }

    private function assertKitchenAccess(User $actor, int $kitchenId): void
    {
        abort_if($actor->role === Role::BARISTA && (int) $actor->kitchen_id !== $kitchenId, 403);
    }
}
