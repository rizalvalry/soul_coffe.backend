<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\Location;
use App\Models\RiderLocationSetup;
use App\Models\User;
use App\Services\Menu\MenuLabels;
use App\Services\RiderLocationSetupService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use UnitEnum;

class SetupLokasiRider extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Setup Lokasi Rider';

    protected static ?string $title = 'Setup Lokasi Rider';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.setup-lokasi-rider';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Operasional');
    }

    public static function canAccess(): bool
    {
        return in_array(Auth::user()?->role, [Role::MANAGEMENT, Role::ADMINISTRATOR, Role::FINANCE], true);
    }

    public function table(Table $table): Table
    {
        abort_unless(self::canAccess(), 403);

        return $table->query(User::query()->where('role', Role::STAFF)->where('is_active', true)
            ->with(['locationSetups' => fn ($query) => $query->with(['location:id,name', 'changedBy:id,name'])->latest('id')]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Rider')->searchable(),
                TextColumn::make('setup_location')->label('Titik jualan')
                    ->getStateUsing(fn (User $record): string => $this->active($record)?->location?->name ?? 'Belum diatur'),
                TextColumn::make('setup_basis')->label('Absen & Closing')
                    ->getStateUsing(fn (User $record): string => match ($this->active($record)?->basis) {
                        'selling_location' => 'Titik jualan', 'kitchen' => 'Dapur', default => 'Belum diatur',
                    }),
                TextColumn::make('setup_changed')->label('Terakhir diubah')
                    ->getStateUsing(function (User $record): string {
                        $setup = $this->active($record);

                        return $setup ? ($setup->changedBy?->name ?? 'Backfill').' · '.$setup->created_at?->format('d M Y H:i') : '—';
                    }),
                TextColumn::make('setup_history')->label('Riwayat')->wrap()
                    ->getStateUsing(fn (User $record): string => $record->locationSetups->map(
                        fn (RiderLocationSetup $row): string => $row->created_at?->format('d M Y').' · '
                            .($row->location?->name ?? 'Tanpa titik').' · '
                            .($row->basis === 'selling_location' ? 'Titik jualan' : 'Dapur')
                            .' · '.($row->changedBy?->name ?? 'Backfill'),
                    )->implode(' / ') ?: '—'),
            ])
            ->recordActions([
                Action::make('setup')->label('Atur setup')
                    ->visible(fn (): bool => Auth::user()?->role === Role::MANAGEMENT)
                    ->fillForm(fn (User $record): array => [
                        'location_id' => $this->active($record)?->location_id,
                        'basis' => $this->active($record)?->basis ?? 'kitchen',
                    ])
                    ->schema([
                        Select::make('location_id')->label('Titik jualan')
                            ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()->nullable(),
                        Select::make('basis')->label('Absen & Closing')
                            ->options(['kitchen' => 'Di Dapur', 'selling_location' => 'Di titik jualan'])
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        abort_unless(Auth::user()?->role === Role::MANAGEMENT, 403);
                        try {
                            app(RiderLocationSetupService::class)->save(Auth::user(), $record,
                                $data['location_id'] ? (int) $data['location_id'] : null, $data['basis']);
                        } catch (RuntimeException $e) {
                            throw ValidationException::withMessages(['location_id' => $e->getMessage()]);
                        }
                        Notification::make()->success()->title('Setup Lokasi Rider tersimpan')->send();
                    }),
            ]);
    }

    private function active(User $rider): ?RiderLocationSetup
    {
        return $rider->locationSetups->firstWhere('superseded_at', null);
    }
}
