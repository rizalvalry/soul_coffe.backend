<?php

namespace App\Services;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Resolves the kitchen for the phone's Produksi flow without changing the actor's role. */
class KitchenContext
{
    public static function authorize(User $user, string $ability = 'view'): void
    {
        abort_unless(PermissionMatrix::can($user, PanelModule::KITCHEN_OPERATIONS, $ability), 403);
    }

    public static function resolve(Request $request, string $ability = 'view'): int
    {
        $user = $request->user();
        self::authorize($user, $ability);

        if ($user->role === Role::BARISTA) {
            if (! $user->kitchen_id) {
                throw ValidationException::withMessages([
                    'kitchen_id' => 'Akun Produksi belum terhubung ke dapur pusat. Hubungi Administrator dulu ya.',
                ]);
            }

            return (int) $user->kitchen_id;
        }

        $data = $request->validate([
            'kitchen_id' => ['required', 'integer', 'exists:central_kitchens,id'],
        ], [
            'kitchen_id.required' => 'Pilih Dapur Pusat dulu ya.',
            'kitchen_id.exists' => 'Dapur Pusat ini tidak ditemukan. Pilih yang lain ya.',
        ]);

        $kitchenId = (int) $data['kitchen_id'];
        if (! CentralKitchen::query()->whereKey($kitchenId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'kitchen_id' => 'Dapur Pusat ini sedang tidak aktif. Pilih yang lain ya.',
            ]);
        }

        return $kitchenId;
    }

    public static function assertMatches(int $selectedKitchenId, ?int $recordKitchenId): void
    {
        if ($recordKitchenId !== null && $selectedKitchenId !== $recordKitchenId) {
            throw ValidationException::withMessages([
                'kitchen_id' => 'Coffee Bike atau request ini ada di dapur lain. Pilih dapur yang sesuai ya.',
            ]);
        }
    }

    public static function assertCartMatches(int $selectedKitchenId, Cart $cart): void
    {
        self::assertMatches($selectedKitchenId, $cart->kitchen_id);
        $assignedKitchenId = StaffAssignment::query()
            ->where('cart_id', $cart->id)
            ->whereDate('operating_date', today()->toDateString())
            ->where('source', 'hand_to_cart')
            ->whereNull('superseded_at')
            ->value('kitchen_id');
        self::assertMatches($selectedKitchenId, $assignedKitchenId === null ? null : (int) $assignedKitchenId);
        self::assertCartStockProvenance($selectedKitchenId, $cart);
    }

    public static function assertCartStockProvenance(int $selectedKitchenId, Cart $cart): void
    {
        self::assertMatches($selectedKitchenId, self::cartStockProvenanceKitchen($cart));
    }

    public static function cartStockProvenanceKitchen(Cart $cart): ?int
    {
        if ($cart->kitchen_id !== null) {
            return null;
        }

        $lastKitchenId = StaffAssignment::query()
            ->where('cart_id', $cart->id)
            ->where('source', 'hand_to_cart')
            ->orderByDesc('operating_date')
            ->orderByDesc('id')
            ->value('kitchen_id');
        if ($lastKitchenId === null) {
            return null;
        }

        $stock = app(StockLedgerService::class)->stockMap(StockLedgerService::CART, $cart->id);

        return collect($stock)->contains(fn (int $qty): bool => $qty > 0) ? (int) $lastKitchenId : null;
    }
}
