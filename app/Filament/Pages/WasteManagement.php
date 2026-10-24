<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Enums\WasteReason;
use App\Exports\ManagementReportExport;
use App\Filament\Concerns\RenameableModule;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\WasteClassification;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\WasteReportService;
use App\Services\WasteTrackingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

class WasteManagement extends Page
{
    use RenameableModule;
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Barang Terbuang';

    protected static ?string $title = 'Barang Terbuang';

    protected string $view = 'filament.pages.waste-management';

    public string $month = '';

    public string $kitchenId = '';

    public string $cartId = '';

    public static function panelModule(): PanelModule
    {
        return PanelModule::WASTE_MANAGEMENT;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'view');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->month = today()->format('Y-m');
    }

    public function maySeeReport(): bool
    {
        return static::canAccess() && in_array(Auth::user()?->role,
            [Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR], true);
    }

    public function sections(): array
    {
        abort_unless($this->maySeeReport(), 403);

        return app(WasteReportService::class)->sections($this->month,
            $this->kitchenId === '' ? null : (int) $this->kitchenId,
            $this->cartId === '' ? null : (int) $this->cartId);
    }

    public function kitchens(): array
    {
        abort_unless($this->maySeeReport(), 403);

        return CentralKitchen::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function carts(): array
    {
        abort_unless($this->maySeeReport(), 403);

        return Cart::query()->orderBy('code')->pluck('code', 'id')->all();
    }

    public function classifications(): LengthAwarePaginator
    {
        abort_unless($this->maySeeReport(), 403);
        $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();

        return WasteClassification::query()->with(['movement.product', 'movement.rawMaterial'])
            ->join('stock_ledger as s', 's.id', '=', 'waste_classifications.stock_ledger_id')
            ->whereBetween('s.created_at', [$start, $start->copy()->endOfMonth()])
            ->when($this->kitchenId !== '', fn ($query) => $query->where('s.kitchen_id', (int) $this->kitchenId))
            ->when($this->cartId !== '', fn ($query) => $query->where('s.location_type', 'cart')
                ->where('s.location_id', (int) $this->cartId))
            ->orderByDesc('s.id')->paginate(30, ['waste_classifications.*'], 'wastePage');
    }

    public function updatingMonth(): void
    {
        $this->resetPage('wastePage');
    }

    public function updatingKitchenId(): void
    {
        $this->resetPage('wastePage');
    }

    public function updatingCartId(): void
    {
        $this->resetPage('wastePage');
    }

    public function exportExcel(): BinaryFileResponse
    {
        $sections = $this->sections();

        return Excel::download(new ManagementReportExport($sections), "barang-terbuang-{$this->month}.xlsx");
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordPromotion')->label('Catat konten/lapak')
                ->visible(fn (): bool => $this->mayRecordPromotion())
                ->authorize(fn (): bool => $this->mayRecordPromotion())
                ->schema([
                    Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('kitchen_id')->label('Dapur')->options(fn (): array => CentralKitchen::query()
                        ->where('is_active', true)
                        ->when(Auth::user()?->role === Role::BARISTA,
                            fn ($query) => $query->whereKey(Auth::user()->kitchen_id))
                        ->pluck('name', 'id')->all())->required(),
                    Select::make('product_id')->label('Produk')->options(fn (): array => Product::query()
                        ->operational()->pluck('name', 'id')->all())->searchable()->required(),
                    TextInput::make('qty')->label('Jumlah cup')->numeric()->integer()->minValue(1)->maxValue(9999)->required(),
                    Textarea::make('note')->label('Catatan konten/lapak')->maxLength(500)->required(),
                ])->action(function (array $data): void {
                    app(WasteTrackingService::class)->recordPromotion(Auth::user(), $data['request_key'],
                        (int) $data['kitchen_id'], (int) $data['product_id'], (int) $data['qty'], $data['note']);
                    Notification::make()->success()->title('Pemakaian konten/lapak tercatat ya.')->send();
                }),
            Action::make('recordPackaging')->label('Catat cup kemasan terbuang')
                ->visible(fn (): bool => $this->mayRecordFinance())
                ->authorize(fn (): bool => $this->mayRecordFinance())
                ->schema([
                    Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                    DatePicker::make('date')->label('Tanggal')->default(today())->required(),
                    Select::make('kitchen_id')->label('Dapur')->options(fn (): array => CentralKitchen::query()
                        ->where('is_active', true)->pluck('name', 'id')->all())->required(),
                    Select::make('raw_material_id')->label('Cup kemasan')->options(fn (): array => RawMaterial::query()
                        ->where('is_packaging_cup', true)->where('is_active', true)
                        ->pluck('name', 'id')->all())->searchable()->required(),
                    TextInput::make('qty')->label('Jumlah cup')->numeric()->integer()->minValue(1)->maxValue(9999)->required(),
                    TextInput::make('unit_cost_minor')->label('Biaya per cup (Rp) jika stok belum terlacak')
                        ->numeric()->integer()->minValue(1),
                    Textarea::make('note')->label('Catatan')->maxLength(500),
                ])->action(function (array $data): void {
                    app(WasteTrackingService::class)->recordPackaging(Auth::user(), $data['request_key'],
                        $data['date'], (int) $data['kitchen_id'], (int) $data['raw_material_id'],
                        (int) $data['qty'], isset($data['unit_cost_minor']) && $data['unit_cost_minor'] !== ''
                            ? (int) $data['unit_cost_minor'] : null, $data['note'] ?? null);
                    Notification::make()->success()->title('Cup kemasan terbuang tercatat ya.')->send();
                }),
            Action::make('correctReason')->label('Koreksi alasan')
                ->visible(fn (): bool => $this->mayCorrect())
                ->authorize(fn (): bool => $this->mayCorrect())
                ->schema([
                    Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                    Select::make('classification_id')->label('Baris waste')
                        ->helperText('Cari ID dari riwayat waste di bawah, atau nama produk.')
                        ->getSearchResultsUsing(fn (string $search): array => app(WasteTrackingService::class)
                            ->classificationOptions(Auth::user(), $search))
                        ->getOptionLabelUsing(fn ($value): ?string => app(WasteTrackingService::class)
                            ->classificationLabel(Auth::user(), (int) $value))
                        ->searchable()->required(),
                    Select::make('reason')->label('Alasan baru')->options(WasteReason::options())->required(),
                    Textarea::make('note')->label('Alasan koreksi')->maxLength(500)->required(),
                ])->action(function (array $data): void {
                    app(WasteTrackingService::class)->correctReason(Auth::user(), $data['request_key'],
                        (int) $data['classification_id'], WasteReason::from($data['reason']), $data['note']);
                    Notification::make()->success()->title('Alasan waste sudah dikoreksi ya.')->send();
                }),
        ];
    }

    private function mayRecordPromotion(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'create')
            && in_array(Auth::user()?->role, [Role::BARISTA, Role::FINANCE], true);
    }

    private function mayRecordFinance(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'create')
            && Auth::user()?->role === Role::FINANCE;
    }

    private function mayCorrect(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'edit')
            && Auth::user()?->role === Role::FINANCE;
    }
}
