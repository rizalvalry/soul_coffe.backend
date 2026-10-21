<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\User;
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
        // These gates stay outside the panel matrix but share the same role inheritance.
        Gate::define('view-management-reports', fn (User $user): bool => $user->is_active && $user->role->grants(Role::MANAGEMENT));
        Gate::define('manage-payroll', fn (User $user): bool => $user->is_active && $user->role->grants(Role::MANAGEMENT));
        Gate::define('manage-tax-settings', fn (User $user): bool => $user->is_active && $user->role->grants(Role::MANAGEMENT));
        Gate::define('manage-accounting-periods', fn (User $user): bool => $user->is_active && $user->role->grants(Role::MANAGEMENT));
        Gate::define('view-fixed-assets', fn (User $user): bool => $user->is_active && $user->role->grants(Role::FINANCE));
        Gate::define('record-fixed-assets', fn (User $user): bool => $user->is_active && $user->role->grants(Role::FINANCE));
        Gate::define('post-fixed-asset-depreciation', fn (User $user): bool => $user->is_active && $user->role->grants(Role::MANAGEMENT));
        Gate::define('void-production', fn (User $user): bool => $user->is_active && $user->role->grants(Role::FINANCE));
        Gate::define('revalue-inventory', fn (User $user): bool => $user->is_active && $user->role->grants(Role::FINANCE));
        Gate::define('post-manual-journal', fn (User $user): bool => $user->is_active && $user->role->grants(Role::FINANCE));
    }
}
