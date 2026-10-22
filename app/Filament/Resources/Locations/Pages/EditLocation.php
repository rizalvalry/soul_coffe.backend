<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Enums\PanelModule;
use App\Filament\Resources\Locations\LocationResource;
use App\Services\LocationSettings;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['use_default_radius'] = ! (bool) $data['geofence_m'];
        if ($data['use_default_radius']) {
            $data['geofence_m'] = app(LocationSettings::class)->absenRadius();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $useDefault = (bool) ($this->data['use_default_radius'] ?? false);
            $radius = $data['geofence_m'] ?? $this->data['geofence_m'] ?? '';
            if ((int) $record->geofence_m !== ($useDefault ? 0 : (int) $radius)) {
                $this->validate(['data.radius_reason' => ['required', 'string', 'min:5', 'max:2000']], [
                    'data.radius_reason.required' => 'Isi alasan perubahan radius dulu ya.',
                    'data.radius_reason.min' => 'Alasan perubahan minimal 5 karakter ya.',
                ]);
                app(LocationSettings::class)->saveRadius(Auth::user(), $record,
                    $radius, (string) $this->data['radius_reason'], PanelModule::LOCATIONS, $useDefault);
            }
            unset($data['geofence_m']);

            return parent::handleRecordUpdate($record, $data);
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
