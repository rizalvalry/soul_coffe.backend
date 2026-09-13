<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Services\Access\PermissionMatrix;
use App\Services\StockLedgerService;
use App\Services\StockOpnameService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use UnitEnum;

/**
 * "Buat Stock Opname" — where a physical count is entered.
 *
 * DELIBERATELY NOT AN ELOQUENT CREATE FORM
 * -----------------------------------------
 * Entering a count is a small workflow: pick a location, see what the ledger currently says for
 * every active product, type what was actually counted for whichever ones differ. That is two
 * tables' worth of rows produced from one interaction, not a single model's columns — the same
 * reason AttendanceSheet, CentralStock and RoleAccessMatrix are plain pages rather than resource
 * CRUD forms.
 *
 * HIDDEN FROM THE SIDEBAR ON PURPOSE
 * ------------------------------------
 * This page has no navigation entry of its own (`shouldRegisterNavigation` is false) so there is
 * exactly one menu item for the whole feature — "Stock Opname", which lists history and this
 * page's own "Buat Stock Opname" button links here. `canAccess()` still gates it on the matrix's
 * `create` ability, independently of whether the link is visible anywhere.
 *
 * THE COUNTED QUANTITY DEFAULTS TO WHAT THE LEDGER SAYS
 * --------------------------------------------------------
 * An operator only has to type a number for the products that actually differ from the system —
 * everything else can be left as-is and submitted unchanged, the same "do not make people retype
 * what the system already knows" principle Settlement and Sales follow.
 */
class StockOpnameEntry extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $title = 'Buat Stock Opname';

    protected string $view = 'filament.pages.stock-opname-entry';

    /** 'kitchen' | 'cart' | '' (not yet chosen). */
    public string $locationKind = '';

    public ?int $locationId = null;

    public string $reason = '';

    /**
     * One row per active product, populated once a location is chosen.
     *
     * @var array<int, array{product_id: int, product_name: string, unit: string, system_qty: int, counted_qty: int}>
     */
    public array $lines = [];

    public bool $loaded = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::STOCK_OPNAME, 'create');
    }

    /** @return array<int, array{value: int, label: string}> */
    public function kitchenOptions(): array
    {
        return CentralKitchen::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (CentralKitchen $kitchen): array => ['value' => $kitchen->id, 'label' => $kitchen->name])
            ->all();
    }

    /** @return array<int, array{value: int, label: string}> */
    public function cartOptions(): array
    {
        return Cart::query()->where('status', '!=', 'retired')->orderBy('code')->get(['id', 'code'])
            ->map(fn (Cart $cart): array => ['value' => $cart->id, 'label' => 'Gerobak '.$cart->code])
            ->all();
    }

    /** Switching the location kind clears whatever was already loaded — the two option lists differ. */
    public function updatedLocationKind(): void
    {
        $this->locationId = null;
        $this->loaded = false;
        $this->lines = [];
    }

    public function updatedLocationId(): void
    {
        $this->loaded = false;
        $this->lines = [];
    }

    /**
     * Pulls every active product's current ledger figure for the chosen location, with the
     * counted quantity pre-filled to match — so typing is only needed where reality disagrees.
     */
    public function loadLocation(): void
    {
        if ($this->locationKind === '' || ! $this->locationId) {
            Notification::make()->warning()->title('Pilih lokasi dulu')->send();

            return;
        }

        $type = $this->locationKind === 'cart' ? StockLedgerService::CART : StockLedgerService::KITCHEN;

        $rows = app(StockOpnameService::class)->draftLines($type, $this->locationId);

        $this->lines = array_map(
            fn (array $row): array => $row + ['counted_qty' => $row['system_qty']],
            $rows,
        );

        $this->loaded = true;
    }

    public function submit(): void
    {
        if (! $this->loaded) {
            Notification::make()->warning()->title('Muat lokasi dulu sebelum menyimpan')->send();

            return;
        }

        $type = $this->locationKind === 'cart' ? StockLedgerService::CART : StockLedgerService::KITCHEN;

        $counts = array_map(
            fn (array $line): array => ['product_id' => $line['product_id'], 'counted_qty' => $line['counted_qty']],
            $this->lines,
        );

        try {
            app(StockOpnameService::class)->create(
                locationType: $type,
                locationId: (int) $this->locationId,
                counts: $counts,
                reason: $this->reason,
                actor: Auth::user(),
            );
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Tidak bisa disimpan')->body($e->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Stock opname tersimpan sebagai draf')
            ->body('Buka menu Stock Opname untuk meninjau selisihnya dan menerapkannya ke buku besar stok.')
            ->send();

        $this->redirect(StockOpnameResource::getUrl());
    }
}
