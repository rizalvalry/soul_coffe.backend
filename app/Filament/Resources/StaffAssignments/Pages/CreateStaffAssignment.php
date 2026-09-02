<?php

namespace App\Filament\Resources\StaffAssignments\Pages;

use App\Filament\Resources\StaffAssignments\StaffAssignmentResource;
use App\Models\Cart;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateStaffAssignment extends CreateRecord
{
    protected static string $resource = StaffAssignmentResource::class;

    /**
     * Two columns are recorded by the system, never typed by the admin:
     *
     * - assigned_by is who made the roster decision. It is an audit fact, so it comes from the
     *   session, not from the form.
     * - kitchen_id is denormalised from the chosen cart. Deriving it here rather than trusting
     *   the submitted payload keeps it consistent with carts.kitchen_id by construction.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['assigned_by'] = Auth::id();
        $data['kitchen_id'] = Cart::find($data['cart_id'])?->kitchen_id;

        return $data;
    }
}
