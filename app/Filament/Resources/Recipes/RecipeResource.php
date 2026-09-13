<?php

namespace App\Filament\Resources\Recipes;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Filament\Resources\Recipes\Schemas\RecipeForm;
use App\Filament\Resources\Recipes\Tables\RecipesTable;
use App\Models\Recipe;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A recipe (bill of materials). See RecipeService for why there is no edit route: a correction is
 * a new version, never an edit of an old one — the exact StockOpnameResource pattern (no edit),
 * but WITH a create route, because unlike a stock opname a new recipe version needs no
 * cross-location lookup step first.
 */
class RecipeResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = Recipe::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Resep';

    protected static ?string $modelLabel = 'Resep';

    protected static ?string $pluralModelLabel = 'Resep';

    protected static ?int $navigationSort = 5;

    public static function panelModule(): PanelModule
    {
        return PanelModule::RECIPES;
    }

    public static function form(Schema $schema): Schema
    {
        return RecipeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecipesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecipes::route('/'),
            'create' => CreateRecipe::route('/create'),
        ];
    }

    /** A version is never edited — see the class docblock. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** History: an old version stays readable even after a newer one supersedes it. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
