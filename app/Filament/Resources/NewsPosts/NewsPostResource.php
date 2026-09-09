<?php

namespace App\Filament\Resources\NewsPosts;

use App\Enums\Role;
use App\Filament\Resources\NewsPosts\Pages\CreateNewsPost;
use App\Filament\Resources\NewsPosts\Pages\EditNewsPost;
use App\Filament\Resources\NewsPosts\Pages\ListNewsPosts;
use App\Filament\Resources\NewsPosts\Schemas\NewsPostForm;
use App\Filament\Resources\NewsPosts\Tables\NewsPostsTable;
use App\Models\NewsPost;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The only resource CONTENT_CREATOR can reach, and the only one whose authorisation is hardcoded
 * rather than matrix-driven: this pairing of roles IS the feature (someone writes the feed, an
 * administrator can pull a post down), so it is not something to leave switchable. Every other
 * resource carries `MatrixGoverned`.
 *
 * Administrators keep full access, because someone has to be able to pull a published post down
 * at short notice without waiting for its author.
 */
class NewsPostResource extends Resource
{
    protected static ?string $model = NewsPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Konten';

    protected static ?string $navigationLabel = 'News Feed';

    /**
     * Renameable like every other menu, under the key `news_feed`.
     *
     * This resource sits outside the access matrix on purpose — its Administrator + Content
     * Creator pairing is the feature itself — but being outside the matrix was never a reason to
     * be the one menu in the panel whose name cannot be changed.
     */
    public static function getNavigationLabel(): string
    {
        return MenuLabels::for('news_feed');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = static::$navigationGroup;

        if ($group === null) {
            return null;
        }

        return MenuLabels::group($group instanceof UnitEnum ? (string) $group->value : (string) $group);
    }

    protected static ?string $modelLabel = 'Artikel';

    protected static ?string $pluralModelLabel = 'Artikel';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canViewAny(): bool
    {
        return in_array(Auth::user()?->role, [Role::ADMINISTRATOR, Role::CONTENT_CREATOR], true);
    }

    public static function canView(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return NewsPostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NewsPostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsPosts::route('/'),
            'create' => CreateNewsPost::route('/create'),
            'edit' => EditNewsPost::route('/{record}/edit'),
        ];
    }
}
