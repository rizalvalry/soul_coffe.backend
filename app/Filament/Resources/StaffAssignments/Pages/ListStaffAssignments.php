<?php

namespace App\Filament\Resources\StaffAssignments\Pages;

use App\Filament\Resources\StaffAssignments\StaffAssignmentResource;
use App\Services\StaffAssignmentCarryForwardService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStaffAssignments extends ListRecords
{
    protected static string $resource = StaffAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The scheduled run at 00:00 covers every ordinary day; this exists for the day this
            // feature ships (today's roster was never carried forward because there was no
            // yesterday-run of it yet) and for an admin who wants today's rows filled in right
            // now rather than waiting on the next cron tick.
            Action::make('carryForwardToday')
                ->label('Terapkan Penugasan Hari Ini')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Terapkan penugasan kemarin ke hari ini?')
                ->modalDescription('Staff yang belum punya penugasan hari ini akan disalin dari penugasan kemarin (gerobak dan lokasi yang sama). Staff atau gerobak yang sudah punya penugasan hari ini tidak akan diubah.')
                ->action(function (StaffAssignmentCarryForwardService $service): void {
                    $result = $service->carryForward();

                    Notification::make()
                        ->success()
                        ->title('Penugasan hari ini diperbarui')
                        ->body(sprintf(
                            '%d penugasan baru dibuat. %d staff sudah punya penugasan, %d gerobak sudah dipakai, %d staff tidak aktif — semuanya dilewati.',
                            $result['created'],
                            $result['skipped_user_conflict'],
                            $result['skipped_cart_conflict'],
                            $result['skipped_inactive'],
                        ))
                        ->send();
                }),

            CreateAction::make(),
        ];
    }
}
