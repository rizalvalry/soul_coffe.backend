<?php

namespace App\Filament\Resources\NewsPosts\Tables;

use App\Models\NewsPost;
use App\Models\NewsPostEngagement;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The creator's own dashboard.
 *
 * The reach column is the point of this screen. A writer who cannot see that only four people
 * opened yesterday's post has no way to get better at this, and a feed nobody reads is the
 * default outcome for every internal comms channel that never measured itself.
 */
class NewsPostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            // Drag-to-reorder writes straight to the column the feed orders by, so the order the
            // writer arranges here is literally the order staff see.
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('cover_path')
                    ->label('Sampul')
                    ->disk('public')
                    ->height(44),

                TextColumn::make('title')
                    ->label('Judul')
                    ->description(fn (NewsPost $record): ?string => $record->kicker)
                    ->searchable()
                    ->wrap()
                    ->limit(60),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'archived' => 'gray',
                        default => 'warning',
                    }),

                IconColumn::make('is_highlighted')
                    ->label('Sorotan')
                    ->boolean(),

                TextColumn::make('audience_roles')
                    ->label('Untuk')
                    ->badge()
                    ->placeholder('semua')
                    ->formatStateUsing(fn ($state) => $state),

                TextColumn::make('reach')
                    ->label('Dibaca')
                    ->state(fn (NewsPost $record): int => NewsPostEngagement::query()
                        ->where('news_post_id', $record->id)
                        ->whereNotNull('read_at')
                        ->count())
                    ->badge()
                    ->color('info'),

                TextColumn::make('reactions')
                    ->label('Reaksi')
                    ->state(fn (NewsPost $record): int => NewsPostEngagement::query()
                        ->where('news_post_id', $record->id)
                        ->whereNotNull('reaction')
                        ->count())
                    ->badge(),

                TextColumn::make('published_at')
                    ->label('Tayang')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('author.name')
                    ->label('Penulis')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'published' => 'Terbit',
                        'archived' => 'Arsip',
                    ]),
                TernaryFilter::make('is_highlighted')->label('Sorotan'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
