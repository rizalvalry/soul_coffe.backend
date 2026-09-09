<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Services\Menu\MenuLabels;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Cache-buster for the hand-written theme stylesheet.
     *
     * The file is served straight from public/ with no build hash, so a browser that has
     * yesterday's copy would keep it. Bump this whenever public/css/bsi-bw.css changes.
     */
    private const THEME_VERSION = '2026-09-10c';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Soul Coffeemate')
            // Phone, not email — see App\Filament\Auth\Login.
            ->login(Login::class)
            // BSI Black & White (BAF): the system is strictly monochrome on one warm "stone"
            // ramp, and its accent is inverted ink rather than a hue — so primary moves off
            // Amber. `danger` keeps Filament's red, which is the one hue the system does allow.
            ->colors([
                'primary' => Color::Stone,
                'gray' => Color::Stone,
            ])
            ->font('Instrument Sans')
            // Plain CSS, deliberately not a Vite/Tailwind theme: this project has no panel
            // build step, so anything requiring compilation would silently not apply — which is
            // exactly how the first version of the absensi screens shipped unstyled. See the
            // header of public/css/bsi-bw.css.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="'.asset('css/bsi-bw.css').'?v='.static::THEME_VERSION.'">',
            )
            // Headings come from the naming screen, in the order declared in MenuLabels::GROUPS.
            // Resolved through a service that falls back to the built-in names if the table is
            // missing, so a panel boot during a fresh migration cannot fail on a cosmetic table.
            ->navigationGroups(MenuLabels::groups())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
