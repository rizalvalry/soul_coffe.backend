<?php

namespace App\Filament\Resources\AbsenExemptions\Pages;

use App\Filament\Resources\AbsenExemptions\AbsenExemptionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAbsenExemption extends CreateRecord
{
    protected static string $resource = AbsenExemptionResource::class;

    /**
     * Stamped here rather than offered as a field: who granted an exemption is a fact about what
     * happened, not something the person filling the form should be able to type.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
