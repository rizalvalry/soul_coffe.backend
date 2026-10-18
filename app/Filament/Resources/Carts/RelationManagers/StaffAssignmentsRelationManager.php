<?php

namespace App\Filament\Resources\Carts\RelationManagers;

use App\Filament\Resources\Carts\CartResource;
use App\Models\Cart;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StaffAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'staffAssignments';

    protected static ?string $title = 'Riwayat pemegang Coffee Bike';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Cart && CartResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('operating_date')->label('Tanggal')->date('d M Y')->sortable(),
                TextColumn::make('user.name')->label('Rider')->searchable(),
                TextColumn::make('location.name')->label('Lokasi')->placeholder('-'),
                TextColumn::make('source')->label('Asal')->formatStateUsing(fn (string $state): string => match ($state) {
                    'hand_to_cart' => 'Add Stock',
                    'carry_forward' => 'Lanjutan lama',
                    default => 'Manual lama',
                }),
                TextColumn::make('superseded_at')->label('Diganti pada')->dateTime('d M Y H:i')->placeholder('-'),
                TextColumn::make('assignedBy.name')->label('Dicatat oleh')->placeholder('-'),
            ])
            ->defaultSort('operating_date', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
