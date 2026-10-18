<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Services\Menu\MenuLabels;
use Filament\FontProviders\LocalFontProvider;
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
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /** Cache-buster for the public theme files. */
    private const THEME_VERSION = '2026-10-19-erp';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('Soul Coffeemate')
            ->brandLogo(new HtmlString('<span class="sc-brand"><img class="sc-brand-mark-teal" src="'.e(asset('images/brand/logo-mark.png')).'" alt=""><img class="sc-brand-mark-white" src="'.e(asset('images/brand/logo-mark-white.png')).'" alt=""><span class="sc-brand-name">SOUL<small>COFFEEMATE</small></span></span>'))
            ->brandLogoHeight('2.5rem')
            ->sidebarWidth('16.5rem')
            ->darkMode()
            // Phone, not email — see App\Filament\Auth\Login.
            ->login(Login::class)
            ->colors([
                'primary' => Color::hex('#007277'),
                'gray' => Color::hex('#587272'),
                'success' => Color::hex('#256B4D'),
                'warning' => Color::hex('#8A5A0F'),
                'danger' => Color::hex('#A63D3D'),
                'info' => Color::hex('#007277'),
            ])
            ->font('Poppins', provider: LocalFontProvider::class)
            // Plain CSS needs no panel build step.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="'.asset('css/soul-teal-signature.css').'?v='.static::THEME_VERSION.'">'
                    .'<link rel="stylesheet" href="'.asset('css/soul-teal-layout.css').'?v='.static::THEME_VERSION.'">'
                    .'<link rel="stylesheet" href="'.asset('css/soul-teal-filament.css').'?v='.static::THEME_VERSION.'">'
                    // Chart.js paints axis labels on canvas, so CSS cannot style them directly.
                    // Filament updates chart.options.color when the panel theme changes.
                    .'<script>window.filamentChartJsGlobalPlugins=[...(window.filamentChartJsGlobalPlugins??[]),{id:"soulTealChartAxisText",beforeUpdate(chart){for(const axis of Object.values(chart.options.scales??{})){if(axis.ticks){axis.ticks.color=chart.options.color}}}}]</script>',
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
