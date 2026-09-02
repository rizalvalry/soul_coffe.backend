<?php

namespace App\Filament\Resources\StaffAssignments\Pages;

use App\Filament\Resources\StaffAssignments\StaffAssignmentResource;
use App\Models\Cart;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditStaffAssignment extends EditRecord
{
    protected static string $resource = StaffAssignmentResource::class;

    /**
     * assigned_by is deliberately not touched here: it records who originally made the roster
     * decision, and an edit does not rewrite that history. kitchen_id must keep following the
     * cart, so it is re-derived whenever the cart changes.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['kitchen_id'] = Cart::find($data['cart_id'])?->kitchen_id;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
