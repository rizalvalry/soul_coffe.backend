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
        // These gates are deliberately outside the panel matrix: Administrator has no bypass.
        Gate::define('view-management-reports', fn (User $user): bool => $user->role === Role::MANAGEMENT);
        Gate::define('manage-payroll', fn (User $user): bool => $user->is_active && $user->role === Role::MANAGEMENT);
        Gate::define('manage-accounting-periods', fn (User $user): bool => $user->role === Role::MANAGEMENT);
        Gate::define('post-manual-journal', fn (User $user): bool => in_array($user->role, [Role::FINANCE, Role::MANAGEMENT], true));
    }
}
