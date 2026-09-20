<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Filament\Concerns\RenameableModule;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\StockOverviewService;
use App\Services\StockAdjustmentService;
use App\Services\StockLedgerService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use UnitEnum;

/**
 * "Stok Terpusat" — every cup that exists right now, before and after it is divided up.
 *
 * The panel could not answer this before. A barista saw their own kitchen from the phone and each
 * rider their own cart, so the only way to know the company total was to add up screens by
 * hand. Production plans against that total.
 *
 * THE GRID IS STILL NEVER EDITED DIRECTLY
 * ------------------------------------------
 * Every figure on the grid stays a projection over `stock_ledger` (R6: append-only,
 * `SUM(qty_delta)`, never a stored counter) — nothing here writes a stock column. The one write
 * this page allows is the "Penyesuaian Stok" header action, which posts an explicit,
 * mandatory-reason compensating ledger row through `StockAdjustmentService`, exactly the kind of
 * write R6 exists to permit: a correction is always a new row, never an edit of an old one. The
 * matrix's `edit` ability governs whether a role can reach that action; `view` alone still shows
 * the grid.
 */
class CentralStock extends Page
{
    use RenameableModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Stok Terpusat';

    protected static ?string $title = 'Stok Terpusat';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.central-stock';

    public static function panelModule(): PanelModule
    {
        return PanelModule::CENTRAL_STOCK;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'view');
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return app(StockOverviewService::class)->snapshot();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('stockAdjustment')
                ->label('Penyesuaian Stok')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => PermissionMatrix::can(Auth::user(), static::panelModule(), 'edit'))
                ->modalHeading('Penyesuaian Stok')
                ->modalDescription('Memposting koreksi ke buku besar stok. Bukan mengubah angka pada tabel — selisih dihitung dari stok sistem saat ini.')
                ->modalSubmitActionLabel('Posting Penyesuaian')
                ->schema([
                    Select::make('location_kind')
                        ->label('Jenis Lokasi')
                        ->options([
                            StockLedgerService::KITCHEN => 'Dapur Pusat',
                            StockLedgerService::CART => 'Gerobak',
                        ])
                        ->required()
                        ->live(),

                    Select::make('location_id')
                        ->label('Lokasi')
                        ->options(function (Get $get): array {
                            return match ($get('location_kind')) {
                                StockLedgerService::CART => Cart::query()
                                    ->where('status', '!=', 'retired')
                                    ->orderBy('code')
                                    ->get(['id', 'code'])
                                    ->mapWithKeys(fn (Cart $cart): array => [$cart->id => 'Gerobak '.$cart->code])
                                    ->all(),
                                StockLedgerService::KITCHEN => CentralKitchen::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all(),
                                default => [],
                            };
                        })
                        ->searchable()
                        ->required()
                        ->live(),

                    Select::make('product_id')
                        ->label('Produk')
                        ->options(fn (): array => Product::query()
                            ->where('is_active', true)
                            ->orderBy('sort_order')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),

                    TextInput::make('counted_qty')
                        ->label('Jumlah sebenarnya')
                        ->helperText('Jumlah yang sesungguhnya ada di lokasi ini sekarang, bukan selisihnya.')
                        ->numeric()
                        ->minValue(0)
                        ->required(),

                    Textarea::make('reason')
                        ->label('Alasan')
                        ->required()
                        ->minLength(10)
                        ->maxLength(500)
                        ->helperText('Wajib, minimal 10 karakter. Tercatat pada baris buku besar yang diposting.'),
                ])
                ->action(function (array $data): void {
                    $product = Product::query()->find($data['product_id']);
                    $locationLabel = $data['location_kind'] === StockLedgerService::CART
                        ? 'Gerobak '.(Cart::query()->find($data['location_id'])?->code ?? $data['location_id'])
                        : (CentralKitchen::query()->find($data['location_id'])?->name ?? (string) $data['location_id']);

                    try {
                        $posted = app(StockAdjustmentService::class)->adjust(
                            locationType: $data['location_kind'],
                            locationId: (int) $data['location_id'],
                            productId: (int) $data['product_id'],
                            countedQty: (int) $data['counted_qty'],
                            reason: $data['reason'],
                            actor: Auth::user(),
                        );
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Tidak bisa memposting penyesuaian')->body($e->getMessage())->send();

                        return;
                    }

                    $delta = $posted->qty_delta;

                    Notification::make()
                        ->success()
                        ->title('Penyesuaian stok diposting')
                        ->body(sprintf(
                            '%s di %s disesuaikan %s%d',
                            $product?->name ?? 'Produk',
                            $locationLabel,
                            $delta > 0 ? '+' : '',
                            $delta,
                        ))
                        ->send();
                }),
        ];
    }
}
