<?php

namespace App\Providers;

use App\Enums\PanelModule;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('view-management-reports', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::MANAGEMENT_REPORTS, 'view'));
        Gate::define('manage-payroll', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::PAYROLL, 'edit'));
        Gate::define('manage-tax-settings', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::TAX_SETTINGS, 'edit'));
        Gate::define('manage-accounting-periods', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::ACCOUNTING_PERIODS, 'edit'));
        Gate::define('view-fixed-assets', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::FIXED_ASSETS, 'view'));
        Gate::define('record-fixed-assets', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::FIXED_ASSETS, 'edit'));
        Gate::define('post-fixed-asset-depreciation', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::FIXED_ASSET_DEPRECIATION, 'edit'));
        Gate::define('void-production', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::INVENTORY_COSTS, 'edit'));
        Gate::define('revalue-inventory', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::INVENTORY_COSTS, 'edit'));
        Gate::define('post-manual-journal', fn (User $user): bool => PermissionMatrix::can($user, PanelModule::JOURNALS, 'edit'));
    }
}
