<div>
    {{-- ========================== 
         ZATARA GLOBAL UI (2026)
         Aesthetic: Aerodynamic Clarity, Glassmorphism, Premium Travel
         ========================== --}}

    {{-- CINEMATIC HERO SECTION (Rounded Container) --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="relative w-full h-[85vh] rounded-[2.5rem] overflow-hidden shadow-2xl shadow-zatara-blue/10 flex items-center justify-center">
            
            {{-- Background Image with slow pan. Above-the-fold LCP element -- eager-loaded on
                 purpose (no loading="lazy"), but capped to the 'hero' conversion instead of
                 whatever resolution it was originally uploaded at. --}}
            @php
                $heroImage = $tenant->getFirstMediaUrl('hero_image', 'hero') ?: $tenant->getFirstMediaUrl('hero_image');
            @endphp
            @if($heroImage)
                <img src="{{ $heroImage }}" alt="Hero Image" class="absolute inset-0 w-full h-full object-cover animate-slowPan" />
            @else
                <div class="absolute inset-0 w-full h-full bg-gradient-to-br from-zatara-blue via-zatara-blue/90 to-slate-900 animate-slowPan">
                    <svg class="absolute inset-0 w-full h-full opacity-10 mix-blend-overlay" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <pattern id="pattern" width="100" height="100" patternUnits="userSpaceOnUse">
                                <circle cx="50" cy="50" r="10" fill="currentColor"></circle>
                                <path d="M0,0 L100,100 M100,0 L0,100" stroke="currentColor" stroke-width="2"></path>
                            </pattern>
                        </defs>
                        <rect width="100%" height="100%" fill="url(#pattern)"></rect>
                    </svg>
                </div>
            @endif
            
            {{-- Overlay --}}
            <div class="absolute inset-0 bg-black/20"></div>

            {{-- Hero Content --}}
            <div class="relative z-10 text-center text-white px-4 -mt-16">
                <h1 class="text-4xl md:text-7xl font-bold tracking-tight mb-6 leading-tight drop-shadow-lg font-arabic">
                    رحلتك القادمة تبدأ من هنا
                </h1>
                <p class="text-lg md:text-2xl font-light text-white/95 max-w-3xl mx-auto drop-shadow-md">
                    اكتشف أروع الوجهات حول العالم بتجربة حجز فائقة السلاسة والرفاهية.
                </p>
            </div>

            {{-- Glassmorphism Search Bar -- this section's own name predates Phase A's
                 de-glassing of .glass-panel (a shared class used across dozens of other storefront
                 elements, correctly kept flat there); this specific card floats directly over the
                 hero photo, where a real backdrop-blur has something to blur, so it now uses the
                 dedicated .hero-glass-panel treatment instead (resources/css/app.css) rather than
                 the shared flat card style. --}}
            <!-- Mobile Search Pill -->
            <div class="block md:hidden absolute bottom-12 left-1/2 -translate-x-1/2 w-11/12 z-20">
                <button class="hero-glass-panel w-full rounded-full py-4 px-6 flex items-center gap-3 text-slate-700 font-medium shadow-lg">
                    <span class="material-symbols-outlined text-zatara-blue">search</span>
                    ابحث عن وجهتك...
                    <span class="material-symbols-outlined mr-auto text-zatara-blue">tune</span>
                </button>
            </div>

            <!-- Desktop Search Bar -->
            <div class="hidden md:block absolute bottom-12 left-1/2 -translate-x-1/2 w-11/12 max-w-4xl z-20">
                <div class="hero-glass-panel rounded-3xl p-4 flex flex-col md:flex-row items-center gap-4">
                    
                    <div class="flex-1 w-full relative">
                        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-zatara-blue/60">location_on</span>
                        <input type="text" wire:model.live.debounce.300ms="searchDestination" placeholder="الوجهة (مثال: سويسرا، دبي)" class="w-full bg-transparent border-none text-slate-800 text-lg font-medium pr-12 pl-4 py-3 focus:ring-0 placeholder:text-slate-400 placeholder:font-light" />
                    </div>
                    
                    <div class="hidden md:block w-[1px] h-10 bg-zatara-blue/10"></div>
                    
                    <div class="flex-1 w-full relative">
                        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-zatara-blue/60">calendar_month</span>
                        <input type="text" wire:model.live="searchDate" placeholder="تاريخ السفر" class="w-full bg-transparent border-none text-slate-800 text-lg font-medium pr-12 pl-4 py-3 focus:ring-0 placeholder:text-slate-400 placeholder:font-light" />
                    </div>

                    {{-- The "guests" field that used to sit here had no wire:model at all -- typing
                         into it did nothing, implying a filter capability this search bar doesn't
                         actually have. Live-confirmed, docs/STOREFRONT_UX_AUDIT.md (Quick Win #5).
                         Removed rather than wired up, since there's no guest-count filter query to
                         connect it to yet. --}}

                    <button class="btn-primary w-full md:w-auto px-10 py-4 text-lg font-bold flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined">search</span>
                        بحث
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- TRUST SIGNALS -- homepage visual-density pass. Every fact here is real and already
         verifiable elsewhere in this app (not invented copy): the tourism license is the same
         $tenant->tourism_license_number already shown in the site footer; WhatsApp support is
         the same contact channel the header/footer buttons already link to; cash/bank-transfer
         are the two payment methods checkout already offers (Stripe is explicitly disabled
         there); cancellation requests are a real, working feature on the customer's my-bookings
         page. No star ratings/review counts here -- no review data model exists in this app. --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-16 mb-8">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
            @if(!empty($tenant->tourism_license_number))
                <div class="flex flex-col items-center text-center gap-3 p-6 rounded-3xl border border-slate-100 bg-white transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                    <span class="material-symbols-outlined text-zatara-gold text-4xl">verified</span>
                    <p class="font-bold text-zatara-blue text-sm">ترخيص سياحي رسمي</p>
                    <p class="text-xs text-slate-400">رقم الترخيص: {{ $tenant->tourism_license_number }}</p>
                </div>
            @endif
            <div class="flex flex-col items-center text-center gap-3 p-6 rounded-3xl border border-slate-100 bg-white transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                <span class="material-symbols-outlined text-zatara-gold text-4xl">chat</span>
                <p class="font-bold text-zatara-blue text-sm">دعم فوري عبر واتساب</p>
                <p class="text-xs text-slate-400">تواصل معنا مباشرة لأي استفسار</p>
            </div>
            <div class="flex flex-col items-center text-center gap-3 p-6 rounded-3xl border border-slate-100 bg-white transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                <span class="material-symbols-outlined text-zatara-gold text-4xl">payments</span>
                <p class="font-bold text-zatara-blue text-sm">خيارات دفع مرنة</p>
                <p class="text-xs text-slate-400">دفع نقدي بالمكتب أو حوالة بنكية</p>
            </div>
            <div class="flex flex-col items-center text-center gap-3 p-6 rounded-3xl border border-slate-100 bg-white transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                <span class="material-symbols-outlined text-zatara-gold text-4xl">event_available</span>
                <p class="font-bold text-zatara-blue text-sm">إلغاء مرن</p>
                <p class="text-xs text-slate-400">تواصل معنا وسنقوم بمعالجة طلب الإلغاء بسرعة</p>
            </div>
        </div>
    </section>

    {{-- FLIGHT PATH DIVIDER --}}
    <div class="max-w-4xl mx-auto my-16 flex items-center gap-4 opacity-50 px-4">
        <div class="w-2 h-2 rounded-full bg-zatara-gold"></div>
        <div class="flight-path flex-1"></div>
        <span class="material-symbols-outlined text-zatara-blue rotate-90 text-3xl">flight</span>
    </div>

    {{-- TRENDING DESTINATIONS HEADER --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-12">
        <div class="flex flex-col md:flex-row justify-between items-end gap-6">
            <div>
                <span class="text-zatara-gold text-sm tracking-widest font-bold block mb-2">الوجهات الرائجة</span>
                <h2 class="text-4xl md:text-5xl font-bold text-zatara-blue">اختر مغامرتك القادمة</h2>
            </div>
        </div>
    </section>

    {{-- TRIP CARDS + FILTER BAR --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32">

        {{-- Storefront redesign: inline pill/chip filter bar, replacing the previous
             `w-full lg:w-72` sidebar. The sidebar's price-range + 2-option category filter
             (داخلي/خارجي, TripTypeEnum) left most of a whole column empty next to the grid --
             a dated e-commerce layout convention. Every wire:model/wire:click binding below is
             identical to the old sidebar's (categories, priceMin, priceMax, resetFilters()),
             so app/Livewire/StorefrontCatalog.php and tests/Feature/StorefrontPhaseFTest.php's
             filter assertions are untouched. --}}
        <div class="flex flex-wrap items-center gap-3 mb-8 bg-white border border-slate-100 rounded-2xl p-3 sm:p-4" x-data="{ priceOpen: false }">
            <span class="text-sm font-bold text-slate-500 px-2 hidden sm:inline">تصفية:</span>

            @foreach($categoryOptions as $option)
                <label class="cursor-pointer select-none px-4 py-2 rounded-full border border-slate-200 text-sm font-bold text-slate-600 transition-all duration-200 has-[:checked]:bg-zatara-blue has-[:checked]:text-white has-[:checked]:border-zatara-blue hover:border-zatara-blue/40">
                    <input type="checkbox" wire:model.live="categories" value="{{ $option->value }}" class="sr-only">
                    {{ $option->getLabel() }}
                </label>
            @endforeach

            <div class="relative" @click.outside="priceOpen = false">
                <button type="button" @click="priceOpen = !priceOpen"
                        class="flex items-center gap-2 px-4 py-2 rounded-full border text-sm font-bold transition-all duration-200 {{ ($priceMin !== null || $priceMax !== null) ? 'bg-zatara-blue text-white border-zatara-blue' : 'border-slate-200 text-slate-600 hover:border-zatara-blue/40' }}">
                    <span class="material-symbols-outlined text-[18px]">tune</span>
                    نطاق السعر
                </button>
                <div x-show="priceOpen" x-transition style="display: none;" class="absolute z-20 top-full mt-2 right-0 bg-white border border-slate-100 rounded-2xl shadow-xl p-4 w-64">
                    <div class="flex items-center gap-3">
                        <div class="flex-1">
                            <label class="text-xs text-slate-400 block mb-1">من</label>
                            <input type="number" min="0" wire:model.live.debounce.500ms="priceMin"
                                   placeholder="0" class="glass-input w-full px-3 py-2 text-sm rounded-xl">
                        </div>
                        <div class="flex-1">
                            <label class="text-xs text-slate-400 block mb-1">إلى</label>
                            <input type="number" min="0" wire:model.live.debounce.500ms="priceMax"
                                   placeholder="{{ $priceCeiling }}" class="glass-input w-full px-3 py-2 text-sm rounded-xl">
                        </div>
                    </div>
                </div>
            </div>

            @if($priceMin !== null || $priceMax !== null || !empty($categories))
                <button type="button" wire:click="resetFilters"
                        class="mr-auto text-sm text-zatara-gold hover:text-zatara-blue font-bold transition-colors">
                    إعادة ضبط التصفية
                </button>
            @endif
        </div>

        <div class="w-full">
        @if($tripTemplates->isEmpty())
            <div class="glass-panel rounded-3xl p-20 text-center">
                <span class="material-symbols-outlined text-6xl text-slate-300 mb-4 block">luggage</span>
                @if($priceMin !== null || $priceMax !== null || !empty($categories) || $searchDestination || $searchDate)
                    <p class="text-slate-500 font-medium text-xl">لا توجد رحلات مطابقة لخيارات التصفية الحالية.</p>
                @else
                    <p class="text-slate-500 font-medium text-xl">نقوم بتجهيز باقات استثنائية قريباً.</p>
                @endif
            </div>
        @else
            <!-- Skeleton Loading State -->
            <div wire:loading class="w-full">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 w-full">
                    @for($i = 0; $i < 6; $i++)
                        <div class="rounded-3xl p-3 bg-white border border-slate-100">
                            <div class="w-full aspect-[4/3] rounded-[1.25rem] bg-slate-100 animate-pulse mb-5"></div>
                            <div class="px-3 pb-3 space-y-3">
                                <div class="h-6 bg-slate-100 rounded-lg animate-pulse w-3/4"></div>
                                <div class="h-4 bg-slate-100 rounded-lg animate-pulse w-full"></div>
                                <div class="h-4 bg-slate-100 rounded-lg animate-pulse w-2/3"></div>
                                <div class="pt-4 border-t border-slate-100 flex justify-between">
                                    <div class="h-8 bg-slate-100 rounded-lg animate-pulse w-24"></div>
                                    <div class="h-10 bg-slate-100 rounded-xl animate-pulse w-24"></div>
                                </div>
                            </div>
                        </div>
                    @endfor
                </div>
            </div>

            <!-- Actual Content -->
            <div wire:loading.remove class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 w-full">
                @foreach($tripTemplates as $template)
                    @php
                        $instances = $template->tripInstances;
                        $firstInstance = $instances->first();
                        $totalRemainingSeats = $instances->sum('remaining_seats');
                    @endphp
                    <a href="{{ route('storefront.trip.details', ['tenant' => $tenant->slug, 'tripTemplate' => $template->slug]) }}" class="group block relative rounded-3xl p-3 bg-white border border-slate-100 shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:shadow-[0_20px_40px_rgba(43,50,128,0.08)] transition-all duration-500 hover:-translate-y-2">
                        
                        {{-- Image Container. Below-the-fold on any page with more than a couple of
                             cards -- loading="lazy" plus a real srcset (Phase B, Section D) instead
                             of always shipping the full-resolution original. --}}
                        <div class="relative w-full aspect-[4/3] rounded-[1.25rem] overflow-hidden mb-5">
                            @php
                                $mediaUrl = $template->getFirstMediaUrl('cover', 'card') ?: $template->getFirstMediaUrl('cover');
                                $mediaUrl2x = $template->getFirstMediaUrl('cover', 'card-2x');
                                if (!$mediaUrl && $firstInstance) {
                                    $mediaUrl = $firstInstance->effectiveCoverUrl('card') ?: $firstInstance->effectiveCoverUrl();
                                    $mediaUrl2x = $firstInstance->effectiveCoverUrl('card-2x');
                                }
                            @endphp
                            @if($mediaUrl)
                                <img src="{{ $mediaUrl }}"
                                     @if($mediaUrl2x) srcset="{{ $mediaUrl }} 800w, {{ $mediaUrl2x }} 1200w" sizes="(max-width: 768px) 100vw, (max-width: 1024px) 50vw, 33vw" @endif
                                     loading="lazy"
                                     alt="{{ $template->title ?? 'صورة الرحلة' }}" class="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-110" />
                            @else
                                <x-trip-cover-placeholder :seed="$template->id" />
                            @endif
                            
                            {{-- Price Badge -- matches the Stitch mockup's overlaid price pill
                                 (stich_with_google_store/stitch_admin_panel_arabic_rebranding),
                                 in addition to (not replacing) the detailed price already shown
                                 below the card, which stays the primary, unambiguous figure. --}}
                            <div class="absolute top-4 right-4 bg-white/95 backdrop-blur-sm px-3 py-1.5 rounded-full shadow-sm z-10">
                                <span class="text-sm font-black text-zatara-blue">{{ number_format($template->starting_price) }} {{ $template->currency ?? 'USD' }}</span>
                            </div>

                            {{-- Duration Tag --}}
                            <div class="absolute bottom-4 right-4 glass-panel px-4 py-1.5 rounded-xl shadow-sm z-10">
                                @if($template->duration_days)
                                    <span class="text-sm font-bold text-zatara-blue">{{ $template->duration_days }} أيام</span>
                                @elseif($firstInstance)
                                    <span class="text-sm font-bold text-zatara-blue">{{ $firstInstance->start_date->format('d M') }}</span>
                                @endif
                            </div>

                            {{-- Multi-departure Tag --}}
                            @if($instances->count() > 1)
                                <div class="absolute top-4 left-4 bg-white/90 backdrop-blur-md px-3 py-1 rounded-full text-xs font-bold text-zatara-blue z-10 shadow-sm flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[14px]">calendar_month</span> {{ $instances->count() }} مواعيد متاحة
                                </div>
                            @endif
                        </div>
                        
                        {{-- Content --}}
                        <div class="px-3 pb-3">
                            <div class="flex justify-between items-start mb-2 gap-4">
                                <h3 class="text-2xl font-bold text-zatara-blue leading-tight">{{ $template->title }}</h3>
                            </div>
                            
                            @if($template->description)
                                <div class="text-slate-500 text-sm font-medium leading-relaxed line-clamp-2 mb-6 prose">
                                    {!! $template->description !!}
                                </div>
                            @else
                                <div class="mb-6 h-10"></div>
                            @endif
                            
                            {{-- Footer of Card --}}
                            <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                                <div>
                                    <p class="text-xs text-slate-400 mb-0.5">تبدأ من</p>
                                    <div class="text-xl font-black text-zatara-blue">
                                        {{ number_format($template->starting_price) }} <span class="text-sm font-medium">{{ $template->currency ?? 'USD' }}</span>
                                    </div>
                                </div>
                                <button class="btn-secondary px-5 py-2.5 text-sm font-bold flex items-center gap-2 group-hover:bg-[#e09825]">
                                    التفاصيل والحجز
                                </button>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>

            {{-- Pagination --}}
            <div class="mt-8">
                {{ $tripTemplates->links() }}
            </div>
        @endif
        </div>
    </section>

</div>