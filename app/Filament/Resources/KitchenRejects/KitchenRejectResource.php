<?php

namespace App\Filament\Resources\KitchenRejects;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\KitchenRejects\Pages\ListKitchenRejects;
use App\Models\CentralKitchen;
use App\Models\KitchenReject;
use App\Services\Access\PermissionMatrix;
use App\Services\KitchenRejectService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class KitchenRejectResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = KitchenReject::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Reject Dapur';

    protected static ?string $modelLabel = 'Reject Dapur';

    public static function panelModule(): PanelModule
    {
        return PanelModule::KITCHEN_REJECT;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(Auth::user()?->role === Role::BARISTA,
                fn (Builder $query): Builder => $query->where('kitchen_rejects.kitchen_id', Auth::user()->kitchen_id));
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query): Builder => self::withSummaries($query))
            ->defaultSort('operating_date', 'desc')
            ->columns([
                TextColumn::make('operating_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('kitchen.name')->label('Dapur')->searchable(),
                TextColumn::make('lines.product.name')->label('Produk')->listWithLineBreaks(),
                TextColumn::make('gross_qty')->label('Jumlah awal')->numeric()->summarize(Sum::make()->label('Total cups awal')),
                TextColumn::make('net_qty')->label('Neto saat ini')->numeric()->summarize(Sum::make()->label('Total cups neto saat ini')),
                TextColumn::make('gross_cost')->label('HPP awal (Rp)')->numeric()
                    ->visible(fn (): bool => self::maySeeCost())
                    ->summarize(Sum::make()->label('Total HPP awal')),
                TextColumn::make('net_cost')->label('HPP neto saat ini (Rp)')->numeric()
                    ->visible(fn (): bool => self::maySeeCost())
                    ->summarize(Sum::make()->label('Total HPP neto saat ini')),
                TextColumn::make('reason_code')->label('Alasan')->formatStateUsing(fn (string $state): string => match ($state) {
                    'DROPPED' => 'Jatuh / tumpah', 'PACKAGING' => 'Kemasan rusak',
                    'QUALITY' => 'Kualitas tidak layak', default => 'Lainnya',
                }),
                TextColumn::make('actor.name')->label('Pelaku'),
                ImageColumn::make('photo.path')->label('Foto')->disk('public')->openUrlInNewTab(),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'NEEDS_REVIEW' => 'Perlu ditinjau', 'REVIEWED' => 'Sudah ditinjau',
                    'CORRECTED' => 'Dikoreksi', default => 'Tercatat',
                })->color(fn (string $state): string => match ($state) {
                    'NEEDS_REVIEW' => 'warning', 'REVIEWED' => 'success',
                    'CORRECTED' => 'gray', default => 'primary',
                }),
            ])
            ->groups(['operating_date'])->defaultGroup('operating_date')
            ->filters([
                Filter::make('tanggal')->schema([
                    DatePicker::make('from')->label('Dari'),
                    DatePicker::make('to')->label('Sampai'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('operating_date', '>=', $date))
                    ->when($data['to'] ?? null, fn (Builder $q, $date): Builder => $q->whereDate('operating_date', '<=', $date))),
                SelectFilter::make('kitchen_id')->label('Dapur')->options(fn (): array => CentralKitchen::query()->pluck('name', 'id')->all()),
                SelectFilter::make('status')->label('Status')->options([
                    'RECORDED' => 'Tercatat', 'NEEDS_REVIEW' => 'Perlu ditinjau',
                    'REVIEWED' => 'Sudah ditinjau', 'CORRECTED' => 'Dikoreksi',
                ]),
            ])
            ->recordActions([
                Action::make('review')->label('Sudah ditinjau')->icon('heroicon-o-check')
                    ->visible(fn (KitchenReject $record): bool => $record->status !== 'CORRECTED'
                        && $record->status !== 'REVIEWED' && self::mayEdit())
                    ->requiresConfirmation()
                    ->action(function (KitchenReject $record): void {
                        app(KitchenRejectService::class)->review($record, Auth::user());
                        Notification::make()->success()->title('Reject sudah ditinjau')->send();
                    }),
                Action::make('correct')->label('Koreksi')->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (KitchenReject $record): bool => $record->status !== 'CORRECTED' && self::mayEdit())
                    ->requiresConfirmation()->schema([
                        Textarea::make('reason')->label('Alasan koreksi')->required()->maxLength(500),
                    ])->action(function (KitchenReject $record, array $data): void {
                        app(KitchenRejectService::class)->correct($record, Auth::user(), $data['reason']);
                        Notification::make()->success()->title('Stok dan jurnal sudah dikoreksi')->send();
                    }),
            ])->toolbarActions([]);
    }

    private static function mayEdit(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::KITCHEN_REJECT, 'edit');
    }

    private static function withSummaries(Builder $query): Builder
    {
        $query->with(['kitchen', 'actor', 'photo', 'lines.product',
            'lines' => fn ($lines) => self::maySeeCost() ? $lines
                : $lines->select('id', 'kitchen_reject_id', 'product_id', 'qty'),
        ])->select('kitchen_rejects.*')->addSelect([
            'gross_qty' => DB::table('kitchen_reject_lines as l')
                ->whereColumn('l.kitchen_reject_id', 'kitchen_rejects.id')
                ->selectRaw('COALESCE(SUM(l.qty), 0)'),
            'net_qty' => DB::table('kitchen_reject_lines as l')
                ->whereColumn('l.kitchen_reject_id', 'kitchen_rejects.id')
                ->selectRaw("CASE WHEN kitchen_rejects.status = 'CORRECTED' THEN 0 ELSE COALESCE(SUM(l.qty), 0) END"),
        ]);

        if (self::maySeeCost()) {
            $cost = fn (bool $net) => DB::table('stock_ledger as s')
                ->leftJoin('stock_cost_resolutions as cr', 'cr.stock_ledger_id', '=', 's.id')
                ->where('s.ref_type', 'kitchen_reject')
                ->whereColumn('s.ref_id', 'kitchen_rejects.id')
                ->selectRaw($net
                    ? "CASE WHEN kitchen_rejects.status = 'CORRECTED' THEN 0 ELSE COALESCE(SUM(s.cost_minor + COALESCE(cr.resolved_cost_minor, 0)), 0) END"
                    : 'COALESCE(SUM(s.cost_minor + COALESCE(cr.resolved_cost_minor, 0)), 0)');
            $query->addSelect(['gross_cost' => $cost(false), 'net_cost' => $cost(true)]);
        }

        return $query;
    }

    public static function maySeeCost(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::INVENTORY_COSTS, 'view');
    }

    public static function getPages(): array
    {
        return ['index' => ListKitchenRejects::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
