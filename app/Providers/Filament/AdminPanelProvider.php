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
            ->colors([
                'primary' => Color::Amber,
            ])
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
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('لوحة القيادة')->icon('heroicon-o-home')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('العمليات اليومية')->icon('heroicon-o-bolt')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('إدارة الرحلات')->icon('heroicon-o-map')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('الحجوزات والعملاء')->icon('heroicon-o-ticket')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('المالية')->icon('heroicon-o-banknotes')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('الإعدادات والإدارة')->icon('heroicon-o-cog')->collapsible(false),
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
