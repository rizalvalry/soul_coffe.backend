<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Kejadian')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                        TextEntry::make('action')->label('Aksi')->badge(),
                        TextEntry::make('actor.name')->label('Pelaku')->placeholder('sistem'),
                        TextEntry::make('actor_role')->label('Role pelaku')->placeholder('-'),
                        TextEntry::make('subject_type')
                            ->label('Objek')
                            ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-'),
                        TextEntry::make('subject_id')->label('ID Objek'),
                        TextEntry::make('ip')->label('IP')->placeholder('-'),
                        TextEntry::make('device_id')->label('Perangkat')->placeholder('-'),
                    ]),

                Section::make('Perubahan')
                    ->columns(2)
                    ->schema([
                        // Cast to array on the model, so re-encoding is what makes it readable
                        // rather than printing "Array".
                        TextEntry::make('before_json')
                            ->label('Sebelum')
                            ->placeholder('-')
                            ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-'),
                        TextEntry::make('after_json')
                            ->label('Sesudah')
                            ->placeholder('-')
                            ->formatStateUsing(fn (mixed $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-'),
                    ]),
            ]);
    }
}
