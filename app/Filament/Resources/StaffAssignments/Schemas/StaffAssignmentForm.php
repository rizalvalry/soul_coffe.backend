<?php

namespace App\Filament\Resources\StaffAssignments\Schemas;

use App\Enums\Role;
use App\Models\StaffAssignment;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class StaffAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Staff')
                    // Only STAFF is ever assigned to a cart; offering every user here would
                    // let an admin roster a Barista onto a bicycle.
                    ->options(fn (): array => User::query()
                        ->where('role', Role::STAFF)
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->live(),

                Select::make('cart_id')
                    ->label('Gerobak')
                    ->relationship('cart', 'code')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),

                Select::make('location_id')
                    ->label('Lokasi')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                DatePicker::make('operating_date')
                    ->label('Tanggal Operasional')
                    ->default(today())
                    ->required()
                    ->live()
                    // R11 is enforced by two unique indexes in the database. Checking here as
                    // well turns an integrity-constraint crash into a sentence the admin can act
                    // on, and names which half of the rule was broken.
                    ->rule(fn (Get $get, ?StaffAssignment $record) => function (string $attribute, mixed $value, callable $fail) use ($get, $record) {
                        $base = StaffAssignment::query()
                            ->whereDate('operating_date', $value)
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()));

                        if ($get('user_id') && (clone $base)->where('user_id', $get('user_id'))->exists()) {
                            $fail('Staff ini sudah ditugaskan pada tanggal tersebut (satu staff satu gerobak per hari).');

                            return;
                        }

                        if ($get('cart_id') && (clone $base)->where('cart_id', $get('cart_id'))->exists()) {
                            $fail('Gerobak ini sudah ada penugasannya pada tanggal tersebut.');
                        }
                    }),

                // kitchen_id is denormalised from the cart at assignment time so kitchen-scoped
                // queries do not have to join through carts. Its value is derived server-side in
                // CreateStaffAssignment/EditStaffAssignment rather than taken from this field --
                // shown here read-only so the admin can see what will be recorded, but an edited
                // browser payload cannot put a cart under the wrong kitchen.
                Select::make('kitchen_id')
                    ->label('Dapur Pusat')
                    ->relationship('kitchen', 'name')
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('Terisi otomatis mengikuti dapur pemasok gerobak yang dipilih.'),
            ]);
    }
}
