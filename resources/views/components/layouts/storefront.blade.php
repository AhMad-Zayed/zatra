<!DOCTYPE html>
<html lang="ar" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $metaDescription ?? ($currentTenant->settings['meta_description'] ?? 'اكتشف أجمل وجهات السفر مع وكالتنا السياحية') }}">
    <meta property="og:title" content="{{ $title ?? ($currentTenant->name ?? 'Zatara Tours & Travel') }}">
    <meta property="og:description" content="{{ $metaDescription ?? ($currentTenant->settings['meta_description'] ?? '') }}">
    <meta property="og:type" content="website">
    <title>{{ $title ?? ($currentTenant->name ?? 'Zatara Tours & Travel') }}</title>

    {{-- Redesign Phase A: typography now matches the admin panel (IBM Plex Sans Arabic,
         AdminPanelProvider.php:50) instead of Tajawal/Cairo -- app.css's @theme already imports
         the face; Material Symbols is a separate icon font, kept here. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @stack('head')

    <style>
        body {
            font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
            background-color: #FFFFFF;
        }
        /* Scrollbar */
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col text-slate-800">

    {{-- ========================== NAVIGATION ========================== --}}
    {{-- The unscrolled nav is transparent with white text/logo, meant to overlay a dark full-
         height hero image -- only the catalog homepage actually has one. Every other page (trip
         details, checkout, booking success, my-bookings, login, legal docs, the magic-link
         portal) starts with a plain white/light background, so that same white-on-transparent nav
         rendered as white-on-white -- effectively invisible logo and nav links on 8 of 9 screens.
         Live-confirmed while verifying Phase A. `scrolled` now starts pre-set to true (readable,
         opaque nav) everywhere except the one page that genuinely has a dark hero behind it. --}}
    <nav x-data="{ scrolled: {{ request()->routeIs('storefront.catalog') ? 'false' : 'true' }}, mobileOpen: false }"
         @scroll.window="scrolled = (window.pageYOffset > 50) || {{ request()->routeIs('storefront.catalog') ? 'false' : 'true' }}"
         :class="scrolled ? 'glass-panel border-b-0' : 'bg-transparent'"
         class="fixed top-0 w-full z-50 transition-all duration-500">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-16">
            <div class="flex justify-between h-24 items-center gap-6">

                {{-- Logo --}}
                @if(isset($currentTenant))
                    <a href="{{ route('storefront.catalog', ['tenant' => $currentTenant->slug]) }}" class="flex items-center gap-3 group flex-shrink-0">
                        <img src="{{ asset('images/logo.png') }}" alt="{{ $currentTenant->name }}" class="h-12 object-contain" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="hidden items-center gap-3">
                            <span class="text-2xl font-bold tracking-wide transition-colors" :class="scrolled ? 'text-zatara-blue' : 'text-white'">
                                {{ $currentTenant->name }}
                            </span>
                        </div>
                    </a>
                @endif

                {{-- Nav Links (Desktop) --}}
                <div class="hidden md:flex items-center gap-8 text-base flex-1 font-medium transition-colors" :class="scrolled ? 'text-slate-700' : 'text-white/90'">
                    @if(isset($currentTenant))
                        <a href="{{ route('storefront.catalog', ['tenant' => $currentTenant->slug]) }}" class="hover:text-zatara-gold transition-colors">الرحلات</a>
                    @endif
                    <a href="#" class="hover:text-zatara-gold transition-colors">وجهاتنا</a>
                    <a href="#" class="hover:text-zatara-gold transition-colors">عن زتارة</a>
                    {{-- "اتصل بنا" pointed at "#" (dead link, no page behind it) -- live-confirmed,
                         docs/STOREFRONT_UX_AUDIT.md (Quick Win #4). Routed to the same WhatsApp
                         contact already used elsewhere on this exact layout rather than left dead;
                         "وجهاتنا"/"عن زتارة" still have no real page to link to at all, so they're
                         left as-is here -- inventing destination/about content is out of scope for
                         a bugfix pass. --}}
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $currentTenant->settings['whatsapp_number'] ?? '970599000000') }}" target="_blank" class="hover:text-zatara-gold transition-colors">اتصل بنا</a>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-4 flex-shrink-0">
                    {{-- Search Icon -- had zero click handler at all (purely decorative, labeled
                         "Micro-interaction" in the original markup) -- live-confirmed while
                         investigating a user report that the search bar "isn't working". On the
                         catalog page (the only page with a search bar) it scrolls up to the hero
                         and opens the mobile search panel if collapsed; on every other page
                         (trip-details, checkout, my-bookings, ...) there's no search UI on the
                         page at all, so it navigates to the catalog page instead. --}}
                    @if(request()->routeIs('storefront.catalog'))
                        <button type="button"
                                @click="document.getElementById('hero')?.scrollIntoView({behavior:'smooth', block:'start'}); window.dispatchEvent(new CustomEvent('open-hero-search'))"
                                class="transition-colors hidden md:block" :class="scrolled ? 'text-zatara-blue hover:text-zatara-gold' : 'text-white hover:text-zatara-gold'">
                            <span class="material-symbols-outlined text-[28px]">search</span>
                        </button>
                    @else
                        <a href="{{ route('storefront.catalog', ['tenant' => $currentTenant->slug]) }}"
                           class="transition-colors hidden md:block" :class="scrolled ? 'text-zatara-blue hover:text-zatara-gold' : 'text-white hover:text-zatara-gold'">
                            <span class="material-symbols-outlined text-[28px]">search</span>
                        </a>
                    @endif

                    {{-- WhatsApp Button -- the unscrolled state used to combine .glass-panel (a
                         solid white background per Phase A's de-glassing, see app.css) with
                         text-white, rendering white text/icon on a white button -- invisible
                         against the hero. Swapped to the same transparent/outlined-over-hero
                         treatment already used by the hero's own "تصفح جميع الرحلات" CTA
                         (storefront-catalog.blade.php) instead of a solid fill. --}}
                    @if(isset($currentTenant))
                        <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $currentTenant->settings['whatsapp_number'] ?? '970599000000') }}"
                           target="_blank"
                           class="hidden md:flex items-center gap-2 px-6 py-2.5 rounded-2xl text-sm font-bold transition-all border"
                           :class="scrolled ? 'bg-zatara-blue text-white border-transparent hover:shadow-lg hover:shadow-zatara-blue/30' : 'bg-transparent text-white border-white/40 hover:bg-white hover:text-zatara-blue'">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                            </svg>
                            واتساب
                        </a>
                    @endif

                    {{-- The logged-in account icon had no logged-out counterpart at all -- a
                         returning customer with a real booking had zero visible way to discover
                         that a login/my-bookings feature even existed (no header icon, no footer
                         link, nothing). Same icon-button pattern already used for the search
                         button right above (scrolled-reactive color, no background), so this
                         doesn't introduce a new visual style -- just a login icon + a
                         desktop-only text label, staying compact enough to sit next to the
                         hamburger button on mobile the same way the account icon always did. --}}
                    @auth('customer')
                        <a href="{{ route('storefront.my-bookings', ['tenant' => $currentTenant->slug]) }}" class="transition-colors" :class="scrolled ? 'text-zatara-blue' : 'text-white'" title="حجوزاتي">
                            <span class="material-symbols-outlined text-[32px]" style="font-variation-settings:'FILL' 0">account_circle</span>
                        </a>
                    @else
                        <a href="{{ route('portal.login', ['tenant' => $currentTenant->slug]) }}" class="flex items-center gap-2 font-bold text-sm transition-colors" :class="scrolled ? 'text-zatara-blue hover:text-zatara-gold' : 'text-white hover:text-zatara-gold'" title="تسجيل الدخول">
                            <span class="material-symbols-outlined text-[28px]">login</span>
                            <span class="hidden md:inline">تسجيل الدخول</span>
                        </a>
                    @endauth

                    {{-- Hamburger Button (Mobile) --}}
                    <button @click="mobileOpen = !mobileOpen"
                        class="md:hidden transition-colors p-1"
                        :class="scrolled ? 'text-zatara-blue' : 'text-white'"
                        aria-label="قائمة التنقل">
                        <svg x-show="!mobileOpen" class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                        <svg x-show="mobileOpen" class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        {{-- Mobile Menu Drawer --}}
        <div x-show="mobileOpen"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             class="md:hidden bg-white border-t border-slate-100 shadow-xl">
            <div class="px-6 py-4 flex flex-col gap-1">
                @if(isset($currentTenant))
                    <a href="{{ route('storefront.catalog', ['tenant' => $currentTenant->slug]) }}"
                       class="py-3 px-2 text-slate-700 font-medium hover:text-zatara-blue border-b border-slate-100 flex items-center gap-3">
                        <span class="material-symbols-outlined text-[20px] text-zatara-gold">flight_takeoff</span> الرحلات
                    </a>
                @endif
                <a href="#" class="py-3 px-2 text-slate-700 font-medium hover:text-zatara-blue border-b border-slate-100 flex items-center gap-3">
                    <span class="material-symbols-outlined text-[20px] text-zatara-gold">explore</span> وجهاتنا
                </a>
                <a href="#" class="py-3 px-2 text-slate-700 font-medium hover:text-zatara-blue border-b border-slate-100 flex items-center gap-3">
                    <span class="material-symbols-outlined text-[20px] text-zatara-gold">info</span> عن زتارة
                </a>
                <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $currentTenant->settings['whatsapp_number'] ?? '970599000000') }}" target="_blank" class="py-3 px-2 text-slate-700 font-medium hover:text-zatara-blue border-b border-slate-100 flex items-center gap-3">
                    <span class="material-symbols-outlined text-[20px] text-zatara-gold">call</span> اتصل بنا
                </a>
                {{-- The mobile drawer never had a login/account entry at all, in either auth
                     state -- not a separate gate on the same link, just entirely missing. Same
                     list-item pattern as the other drawer links above. --}}
                @if(isset($currentTenant))
                    @auth('customer')
                        <a href="{{ route('storefront.my-bookings', ['tenant' => $currentTenant->slug]) }}"
                           class="py-3 px-2 text-slate-700 font-medium hover:text-zatara-blue flex items-center gap-3">
                            <span class="material-symbols-outlined text-[20px] text-zatara-gold">account_circle</span> حجوزاتي
                        </a>
                    @else
                        <a href="{{ route('portal.login', ['tenant' => $currentTenant->slug]) }}"
                           class="py-3 px-2 text-slate-700 font-medium hover:text-zatara-blue flex items-center gap-3">
                            <span class="material-symbols-outlined text-[20px] text-zatara-gold">login</span> تسجيل الدخول
                        </a>
                    @endauth
                @endif
                @if(isset($currentTenant))
                    <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $currentTenant->settings['whatsapp_number'] ?? '970599000000') }}"
                       target="_blank"
                       class="mt-3 flex items-center justify-center gap-2 bg-green-500 text-white py-3 rounded-xl font-bold">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        تواصل عبر واتساب
                    </a>
                @endif
            </div>
        </div>
    </nav>

    {{-- ========================== MAIN ========================== --}}
    <main class="flex-grow">
        {{ $slot }}
    </main>

    {{-- ========================== FOOTER ========================== --}}
    <footer class="bg-[#0f172a] text-white mt-32 relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.03] pointer-events-none" style="background-image: radial-gradient(#f59e0b 1px, transparent 1px); background-size: 24px 24px;"></div>
        <div class="max-w-7xl mx-auto px-6 md:px-16 py-16 relative z-10 border-t-4 border-zatara-gold">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-12 mb-12">
                {{-- Column 1: Agency Info & License --}}
                <div class="col-span-1">
                    @if(isset($currentTenant))
                        <div class="font-bold text-3xl text-zatara-gold mb-4">{{ $currentTenant->name }}</div>
                    @endif
                    <p class="text-sm text-slate-400 font-light leading-relaxed mb-4">
                        نقدم لك تجارب سفر مصممة بعناية لتلبي طموحك في اكتشاف العالم برفاهية مطلقة وخدمة لا تُضاهى.
                    </p>
                    @if(!empty($currentTenant->tourism_license_number))
                        <div class="inline-block bg-white/5 border border-white/10 rounded-lg px-3 py-2 text-xs text-slate-300">
                            <span class="block text-slate-500 mb-1">ترخيص وزارة السياحة</span>
                            <strong class="text-white">{{ $currentTenant->tourism_license_number }}</strong>
                        </div>
                    @endif
                </div>

                {{-- Column 2: Legal & Trust --}}
                <div>
                    <h4 class="font-bold text-white mb-4 text-lg">روابط سريعة</h4>
                    <ul class="space-y-3 text-slate-400 font-light text-sm">
                        <li><a href="{{ route('storefront.legal', ['tenant' => $currentTenant->slug, 'document' => 'terms']) }}" class="hover:text-zatara-gold transition-colors">الشروط والأحكام</a></li>
                        <li><a href="{{ route('storefront.legal', ['tenant' => $currentTenant->slug, 'document' => 'privacy']) }}" class="hover:text-zatara-gold transition-colors">سياسة الخصوصية</a></li>
                        <li><a href="{{ route('storefront.legal', ['tenant' => $currentTenant->slug, 'document' => 'refund']) }}" class="hover:text-zatara-gold transition-colors">سياسة الاسترجاع والإلغاء</a></li>
                    </ul>
                </div>

                {{-- Column 3: FAQs --}}
                <div>
                    <h4 class="font-bold text-white mb-4 text-lg">الأسئلة الشائعة</h4>
                    <ul class="space-y-3 text-slate-400 font-light text-sm">
                        @php
                            $faqs = $currentTenant->settings['faqs'] ?? [];
                        @endphp
                        @forelse(array_slice($faqs, 0, 4) as $faq)
                            <li class="line-clamp-1"><a href="#" class="hover:text-zatara-gold transition-colors" title="{{ $faq['question'] ?? '' }}">{{ $faq['question'] ?? '' }}</a></li>
                        @empty
                            <li>لا توجد أسئلة شائعة حالياً.</li>
                        @endforelse
                    </ul>
                </div>

                {{-- Column 4: Contact & Socials --}}
                <div>
                    <h4 class="font-bold text-white mb-4 text-lg">تواصل معنا</h4>
                    <div class="space-y-3 text-slate-400 font-light text-sm mb-6">
                        @if(!empty($currentTenant->settings['office_address']))
                            <div class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-[18px] text-zatara-gold shrink-0">location_on</span>
                                <span>{{ $currentTenant->settings['office_address'] }}</span>
                            </div>
                        @endif
                        @if(!empty($currentTenant->settings['contact_phone']))
                            <div class="flex items-center gap-2">
                                <span class="material-symbols-outlined text-[18px] text-zatara-gold shrink-0">call</span>
                                <span dir="ltr">{{ $currentTenant->settings['contact_phone'] }}</span>
                            </div>
                        @endif
                        @if(!empty($currentTenant->settings['working_hours']))
                            <div class="flex items-start gap-2">
                                <span class="material-symbols-outlined text-[18px] text-zatara-gold shrink-0">schedule</span>
                                <span>{{ $currentTenant->settings['working_hours'] }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex gap-3">
                        @if(!empty($currentTenant->settings['facebook_url']))
                            <a href="{{ $currentTenant->settings['facebook_url'] }}" target="_blank" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white hover:bg-zatara-gold hover:text-[#0f172a] transition-all" title="Facebook">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </a>
                        @endif
                        @if(!empty($currentTenant->settings['instagram_url']))
                            <a href="{{ $currentTenant->settings['instagram_url'] }}" target="_blank" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white hover:bg-zatara-gold hover:text-[#0f172a] transition-all" title="Instagram">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
                            </a>
                        @endif
                        @if(!empty($currentTenant->settings['tiktok_url']))
                            <a href="{{ $currentTenant->settings['tiktok_url'] }}" target="_blank" class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center text-white hover:bg-zatara-gold hover:text-[#0f172a] transition-all" title="TikTok">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
                            </a>
                        @endif
                        @if(!empty($currentTenant->settings['whatsapp_number']))
                            <a href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $currentTenant->settings['whatsapp_number']) }}" target="_blank" class="w-10 h-10 rounded-full bg-[#25D366]/20 flex items-center justify-center text-[#25D366] hover:bg-[#25D366] hover:text-white transition-all" title="WhatsApp">
                                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </a>
                        @endif
                    </div>
                </div>
            </div>
            
            <div class="border-t border-white/10 pt-8 flex flex-col md:flex-row justify-between items-center gap-4">
                <div class="text-sm text-center md:text-right text-slate-500 font-light">
                    © {{ date('Y') }} {{ $currentTenant->name ?? 'Zatara Tours & Travel' }}. جميع الحقوق محفوظة.
                </div>
            </div>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
