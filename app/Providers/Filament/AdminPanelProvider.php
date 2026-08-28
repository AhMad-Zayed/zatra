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
                // Azure Horizon design system — semantic values taken from the spec's prose
                // ("Brand & Style" / "Colors" sections), not the raw M3 token YAML (which uses a
                // token architecture — surface-container-*, on-*, *-fixed-dim — that Filament's
                // six-role palette system has no slots for). 'accent' is a 7th registered color
                // (Filament allows arbitrary named colors beyond the built-in six — see
                // ColorManager::getColors()), used sparingly for CTA/conversion actions per spec.
                'primary' => Color::hex('#00355f'), // Sapphire Blue
                'accent' => Color::hex('#fe9835'), // Sunset Orange — CTA/conversion actions only
                'success' => Color::hex('#059669'), // Emerald 600
                'danger' => Color::hex('#e11d48'), // Rose 600
                'warning' => Color::hex('#f59e0b'), // Amber 500
                'info' => Color::Sky,
                'gray' => Color::Slate, // cool/blue-tinted neutrals — Slate-50 (#f8fafc) matches
                                         // the spec's surface background value exactly.
            ])
            ->font('IBM Plex Sans Arabic', provider: \Filament\FontProviders\GoogleFontProvider::class)
            ->theme(asset('css/filament/admin/theme.css'))
            ->sidebarWidth('280px')
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
            // The topbar has no top-navigation content when using sidebar navigation (Filament
            // only renders logo/nav-groups into the topbar in ->topNavigation() mode), leaving a
            // wide empty stretch between the sidebar and the search/user-menu cluster. Filling
            // the topbar's leading edge with the active agency name gives that space an
            // intentional purpose instead of reading as an unfinished gap.
            ->renderHook(
                \Filament\View\PanelsRenderHook::TOPBAR_START,
                fn (): \Illuminate\Contracts\View\View => view('filament.partials.topbar-tenant-label'),
            )
            ->tenant(Tenant::class)
            ->tenantProfile(\App\Filament\Pages\Tenancy\EditTenantProfile::class)
            ->tenantMiddleware([
                \App\Http\Middleware\ApplyTenantScopes::class,
            ], isPersistent: true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            // Group-level icons are intentionally omitted: Filament forbids combining a group
            // icon with icons on that group's items, and every resource below already carries
            // its own navigationIcon — those are what actually render in the expanded sidebar.
            //
            // Logistics (pickup points/routes) folded into "الرحلات والفنادق" and Customers
            // folded into "الحجوزات" — both per explicit sign-off, no longer standalone groups.
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('الرئيسية')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('الحجوزات')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('الرحلات والفنادق')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('المالية')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('التقارير')->collapsible(false),
                \Filament\Navigation\NavigationGroup::make('الإعدادات')->collapsible(false),
            ])
            // Admin panel UX audit, quick win: discoverWidgets() already registers every widget
            // in this directory, ordered by each widget's own $sort (0-4, already set up on all
            // six classes below). The explicit ->widgets([...]) array that used to sit here
            // re-registered 4 of those same 6 classes a second time -- Filament rendered
            // QuickActionsWidget (and its "العملاء" tile), DashboardStatsOverview,
            // StaffOverviewWidget, and TodaysDeparturesWidget twice on every dashboard load,
            // while AutomationStatusWidget and RevenueChart (never in that list) rendered once.
            // Discovery alone now registers all six, exactly once each, in their intended order.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
