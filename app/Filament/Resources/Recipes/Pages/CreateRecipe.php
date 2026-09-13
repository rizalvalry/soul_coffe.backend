<?php

namespace App\Filament\Resources\Recipes\Pages;

use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Recipe;
use App\Services\RecipeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Routes creation through RecipeService::create() instead of a plain Eloquent insert — a new
 * recipe is a new VERSION (version number, effective_from, created_by are all decided there, not
 * typed on the form), and the service is what enforces "at least one line, no duplicate raw
 * material, every qty_per_unit > 0".
 */
class CreateRecipe extends CreateRecord
{
    protected static string $resource = RecipeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(RecipeService::class)->create(
            productId: (int) $data['product_id'],
            lines: $data['lines'] ?? [],
            actor: Auth::user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
