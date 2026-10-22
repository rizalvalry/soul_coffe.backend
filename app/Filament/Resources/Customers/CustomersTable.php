<?php

namespace App\Filament\Resources\Customers;

use App\Enums\PanelModule;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\Access\PermissionMatrix;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable(),
                TextColumn::make('email')->label('Email')
                    ->formatStateUsing(fn (?string $state): string => self::maskEmail($state))
                    ->searchable(),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'ACTIVE' ? 'Aktif' : 'Nonaktif')
                    ->color(fn (string $state): string => $state === 'ACTIVE' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Daftar')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('last_login_at')->label('Login terakhir')->dateTime('d M Y H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('toggleStatus')
                    ->label(fn (Customer $record): string => $record->status === 'ACTIVE' ? 'Nonaktifkan' : 'Aktifkan')
                    ->color(fn (Customer $record): string => $record->status === 'ACTIVE' ? 'danger' : 'success')
                    ->visible(fn (): bool => PermissionMatrix::can(Auth::user(), PanelModule::CUSTOMERS, 'edit'))
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')->label('Alasan')->required()->minLength(3)->maxLength(500),
                    ])
                    ->action(function (Customer $record, array $data): void {
                        DB::transaction(function () use ($record, $data): void {
                            abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::CUSTOMERS, 'edit'), 403);
                            $customer = Customer::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
                            $before = $customer->status;
                            $after = $before === 'ACTIVE' ? 'DISABLED' : 'ACTIVE';
                            $customer->update(['status' => $after]);
                            if ($after === 'DISABLED') {
                                $customer->tokens()->delete();
                            }
                            AuditLog::recordAction(Auth::user(), 'customer.status_changed', $customer,
                                ['status' => $before], ['status' => $after, 'reason' => trim($data['reason'])]);
                        });
                        Notification::make()->success()->title('Status pelanggan sudah diperbarui ya.')->send();
                    }),
            ]);
    }

    private static function maskEmail(?string $email): string
    {
        if (! $email || ! str_contains($email, '@')) {
            return '-';
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
