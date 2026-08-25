<?php

namespace App\Providers\Filament;

use App\Models\Tenant;
use Filament\Http\Middleware\Authenticate;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->unsavedChangesAlerts()
            ->colors([
                // Zatara brand palette (matches resources/css/app.css --color-zatara-*, already
                // in production use on the storefront) — carries the one existing brand identity
                // into the admin panel instead of inventing a second, unrelated one.
                'primary' => Color::hex('#2B3280'), // Zatara Blue
                'danger' => Color::hex('#A13D44'), // Zatara Red
                'warning' => Color::hex('#F4A93F'), // Zatara Gold
                'info' => Color::Sky, // distinct from primary blue to avoid semantic confusion
            ])
            ->font('Tajawal')
            ->brandName('زتارة')
            ->brandLogo(new HtmlString(<<<'HTML'
                <div class="flex items-center gap-2 h-full">
                    <svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                        <circle cx="14" cy="14" r="14" fill="#2B3280"/>
                        <path d="M9 19L19 9M19 9H12M19 9V16" stroke="#F4A93F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="zatara-wordmark">زتارة</span>
                    <style>
                        .zatara-wordmark { color: #1E2158; font-weight: 700; font-size: 1.125rem; line-height: 1; }
                        .dark .zatara-wordmark { color: #ffffff; }
                    </style>
                </div>
                HTML))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.svg'))
            ->tenant(Tenant::class)
            ->tenantProfile(\App\Filament\Pages\Tenancy\EditTenantProfile::class)
            ->tenantMiddleware([
                \App\Http\Middleware\ApplyTenantScopes::class,
            ], isPersistent: true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            // Group-level icons are intentionally omitted: Filament forbids combining a group
            // icon with icons on that group's items, and every resource below already carries
            // its own navigationIcon — those are what actually render in the expanded sidebar.
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('الحجوزات والعملاء')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('الرحلات والفنادق')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('اللوجستيات')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('المالية')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('الإعدادات والإدارة')->collapsible(false),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                \App\Filament\Widgets\DashboardStatsOverview::class,
                // BookingStatsWidget was merged into DashboardStatsOverview (CRIT-001 fix)
                \App\Filament\Widgets\TodaysDeparturesWidget::class, // HIGH-006
            ])
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
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
