<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Support\PhoneNumber;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Unique;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255),

                TextInput::make('phone_e164')
                    ->label('Nomor HP')
                    ->tel()
                    ->required()
                    ->helperText('Boleh diketik 08…, 62…, atau +62… — disimpan sebagai +62…')
                    // Normalising on the way in is what makes the panel and the API agree; a
                    // user saved as 0811… would never match a login normalised to +62811…
                    ->dehydrateStateUsing(fn (string $state): string => PhoneNumber::normalize($state))
                    // The uniqueness check must see the same normalised value the save will
                    // write, otherwise "0811…" and "+62811…" both pass and collide in the DB.
                    ->rule(fn (?object $record) => function (string $attribute, mixed $value, callable $fail) use ($record) {
                        $exists = \App\Models\User::query()
                            ->where('phone_e164', PhoneNumber::normalize((string) $value))
                            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                            ->exists();

                        if ($exists) {
                            $fail('Nomor HP ini sudah dipakai akun lain.');
                        }
                    }),

                Select::make('role')
                    ->label('Role')
                    ->options(collect(Role::cases())->mapWithKeys(
                        fn (Role $role) => [$role->value => $role->label()]
                    ))
                    ->required()
                    ->live(),

                Select::make('kitchen_id')
                    ->label('Dapur Pusat')
                    ->relationship('kitchen', 'name')
                    ->helperText('Hanya berlaku untuk Barista — role lain dikosongkan.')
                    // Mirrors the schema comment on users.kitchen_id: "Only relevant for BARISTA
                    // users; null for every other role."
                    ->visible(fn (Get $get): bool => $get('role') === Role::BARISTA->value)
                    ->required(fn (Get $get): bool => $get('role') === Role::BARISTA->value)
                    ->dehydrateStateUsing(fn (mixed $state, Get $get) => $get('role') === Role::BARISTA->value ? $state : null),

                TextInput::make('password')
                    ->label('Kata Sandi')
                    ->password()
                    ->revealable()
                    ->minLength(6)
                    // Required only when creating. On edit an empty box means "leave the current
                    // password alone" — dehydrated away below so it is never written as empty.
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Kosongkan bila tidak ingin mengubah kata sandi.'
                        : null)
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                    // No manual Hash::make here: the model casts `password => 'hashed'`, and
                    // hashing twice would produce a hash of a hash that no login can match.

                TextInput::make('pin_hash')
                    ->label('PIN Staff')
                    ->password()
                    ->revealable()
                    ->numeric()
                    ->minLength(6)
                    ->maxLength(6)
                    ->helperText('6 digit. Dipakai Rider sebagai jalur cadangan saat HP staff mati (E7). Kosongkan bila tidak diubah.')
                    // Only STAFF carries a PIN -- see UserSeeder, which sets pin_hash for STAFF only.
                    ->visible(fn (Get $get): bool => $get('role') === Role::STAFF->value)
                    // Never render the stored hash back into the box: an admin who opened the
                    // record and pressed Save would otherwise re-hash the hash, and the staff
                    // member's real PIN would stop working with no visible cause.
                    ->formatStateUsing(fn (): ?string => null)
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    // pin_hash has no 'hashed' cast, so unlike password it must be hashed here.
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state)),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->helperText('Akun nonaktif tidak bisa login, baik di aplikasi maupun panel ini.'),
            ]);
    }
}
