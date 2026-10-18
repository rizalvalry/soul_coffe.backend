<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Enums\PanelModule;
use App\Exports\SettlementQueueExport;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Models\Cart;
use App\Services\Access\PermissionMatrix;
use App\Services\SettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;

    public string $selectedDate = '';

    public function mount(): void
    {
        parent::mount();
        $this->selectedDate = today()->toDateString();
    }

    public function updatedSelectedDate(): void
    {
        $this->resetTable();
    }

    public function queue(): array
    {
        return app(SettlementService::class)->queue($this->date());
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->whereDate('operating_date', $this->date()->toDateString()));
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.settlements.queue'),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')
                ->label('Catat Setoran')
                ->visible(fn (): bool => PermissionMatrix::can(Auth::user(), PanelModule::SETTLEMENTS, 'create'))
                ->schema([
                    Select::make('cart_id')->label('Coffee Bike')->required()
                        ->options(fn (): array => Cart::query()->whereIn('id', collect($this->queue())
                            ->filter(fn (array $row): bool => ! $row['closing_required'] || $row['closing_id'] !== null)->pluck('cart_id')->all())
                            ->orderBy('code')->pluck('code', 'id')->all()),
                    TextInput::make('cash')->label('Tunai dihitung (Rp)')->integer()->minValue(0)->maxValue(1000000000000)->required(),
                    TextInput::make('qris')->label('QRIS diterima (Rp)')->integer()->minValue(0)->maxValue(1000000000000)->required(),
                    TextInput::make('transfer')->label('Transfer diterima (Rp)')->integer()->minValue(0)->maxValue(1000000000000)->required(),
                    Textarea::make('variance_reason')->label('Alasan selisih (opsional)')->maxLength(500),
                ])
                ->action(function (array $data, SettlementService $service): void {
                    abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::SETTLEMENTS, 'create'), 403);
                    try {
                        $service->record(
                            Cart::query()->findOrFail($data['cart_id']), Auth::user(),
                            ['cash' => (int) $data['cash'], 'qris' => (int) $data['qris'], 'transfer' => (int) $data['transfer']],
                            $data['variance_reason'] ?? null, $this->date(),
                        );
                        Notification::make()->success()->title('Setoran tercatat')->send();
                    } catch (RuntimeException $exception) {
                        Notification::make()->danger()->title('Setoran gagal dicatat')->body($exception->getMessage())->send();
                    }
                }),
            Action::make('exportExcel')
                ->label('Ekspor Excel Harian')
                ->action(function (): BinaryFileResponse {
                    abort_unless(SettlementResource::canViewAny(), 403);

                    return Excel::download(new SettlementQueueExport($this->date()), 'setoran_'.$this->date()->toDateString().'.xlsx');
                }),
        ];
    }

    private function date(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->selectedDate ?: today()->toDateString())->startOfDay();
    }
}
