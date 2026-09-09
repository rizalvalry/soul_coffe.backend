<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Support\PhoneNumber;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                    ->helperText('Boleh diketik 08…, 62…, atau +62… — disimpan sebagai 08…')
                    // Normalising on the way in is what makes the panel and the API agree; a
                    // user saved as +62811… would never match a login normalised to 0811…
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

                // ── Employment profile ────────────────────────────────────────────────
                // Read by the monthly absensi sheet (Absensi → Laporan Absensi). Kept on the
                // person's own record rather than in a second roster, so nobody is typed twice.
                TextInput::make('nik')
                    ->label('NIK')
                    ->maxLength(32)
                    // Nullable on purpose: someone can start work before a NIK is issued, and
                    // refusing to record them would push that name back into a spreadsheet.
                    ->unique(ignoreRecord: true)
                    ->helperText('Nomor induk karyawan. Boleh dikosongkan bila belum ada.'),

                Select::make('uniform_size')
                    ->label('SIZE (seragam)')
                    ->options(['S' => 'S', 'M' => 'M', 'L' => 'L', 'XL' => 'XL', 'XXL' => 'XXL'])
                    ->native(false),

                TextInput::make('monthly_libur_quota')
                    ->label('Jatah Klibur / bulan')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(31)
                    ->default(4)
                    ->required()
                    ->helperText('Hari libur berbayar per bulan. Libur di atas angka ini muncul sebagai "Lebih dari Jatah" di laporan absensi.'),

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

                // ── Biodata ───────────────────────────────────────────────────────────
                // Collapsed by default: adding a person needs a name, a phone and a role, and
                // burying those three under twelve optional fields would make the common case
                // slower. Nothing in here is required — a record saves cleanly with the whole
                // section left untouched, because HR data arrives in pieces.
                Section::make('Biodata & Data Kepegawaian')
                    ->description('Semua opsional — boleh dikosongkan dan tetap aman disimpan.')
                    ->collapsed()
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('national_id')
                            ->label('No. KTP')
                            ->maxLength(32)
                            ->helperText('Berbeda dari NIK karyawan di atas.'),

                        DatePicker::make('birth_date')
                            ->label('Tanggal Lahir')
                            ->native(false)
                            ->maxDate(now()),

                        TextInput::make('birth_place')
                            ->label('Tempat Lahir')
                            ->maxLength(255),

                        Select::make('gender')
                            ->label('Jenis Kelamin')
                            ->options(['L' => 'Laki-laki', 'P' => 'Perempuan'])
                            ->native(false),

                        Select::make('marital_status')
                            ->label('Status Perkawinan')
                            ->options([
                                'belum_menikah' => 'Belum menikah',
                                'menikah' => 'Menikah',
                                'duda' => 'Duda',
                                'janda' => 'Janda',
                            ])
                            ->native(false),

                        DatePicker::make('joined_at')
                            ->label('Tanggal Mulai Bekerja')
                            ->native(false),

                        Textarea::make('address')
                            ->label('Alamat')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),

                        TextInput::make('emergency_contact_name')
                            ->label('Kontak Darurat — Nama')
                            ->maxLength(255),

                        TextInput::make('emergency_contact_phone')
                            ->label('Kontak Darurat — Nomor HP')
                            ->tel()
                            ->maxLength(32),

                        TextInput::make('bank_name')
                            ->label('Bank')
                            ->maxLength(255),

                        TextInput::make('bank_account_number')
                            ->label('No. Rekening')
                            ->maxLength(64),

                        TextInput::make('bank_account_holder')
                            ->label('Nama Pemilik Rekening')
                            ->maxLength(255)
                            ->helperText('Isi bila berbeda dari nama karyawan.'),
                    ]),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Catatan bebas tentang orang ini — hal yang tidak punya kolomnya sendiri.')
                    ->columnSpanFull(),
            ]);
    }
}
