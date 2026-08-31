<div x-data="{
    showGallery: false,
    travelerCount: 1,
    maxTravelers: {{ $selectedInstance && $selectedInstance->remaining_seats > 0 ? min($selectedInstance->remaining_seats, 10) : 10 }},
}">
    {{-- ========================== 
         ZATARA GLOBAL UI (2026) - TRIP DETAILS
         Aesthetic: Aerodynamic Clarity, Glassmorphism
         ========================== --}}

    {{-- HERO IMAGE & BREADCRUMB --}}
    <section class="w-full pt-32 pb-12 px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
        {{-- Breadcrumbs --}}
        <nav class="flex text-sm text-slate-500 mb-6 font-medium">
            <a href="{{ route('storefront.catalog', ['tenant' => $tenant->slug]) }}" class="hover:text-zatara-blue transition-colors">الرئيسية</a>
            <span class="mx-2">/</span>
            <span class="text-zatara-blue">{{ $template->title }}</span>
        </nav>

        @if (session()->has('error'))
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-lg">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <span class="material-symbols-outlined text-red-500">error</span>
                    </div>
                    <div class="mr-3">
                        <p class="text-sm text-red-700 font-bold">
                            {{ session('error') }}
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Main Title --}}
        <div class="flex flex-col md:flex-row justify-between items-start gap-6 mb-8">
            <div>
                <h1 class="text-4xl md:text-5xl font-bold text-zatara-blue leading-tight mb-4">
                    {{ $template->title }}
                </h1>
                <div class="flex items-center gap-6 text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-zatara-gold">calendar_month</span>
                        @if($instances->count() == 1 && $selectedInstance)
                            {{-- Latin date range inside an RTL page bidi-reorders visually
                                 ("04 Sep, 2026 - 09 Sep, 2026" rendering as "Sep, 2026 - 09 Sep,
                                 2026 04") without an explicit LTR isolation -- same dir="ltr"
                                 pattern already used correctly for this exact range on the
                                 checkout summary card (checkout-wizard.blade.php:459-460). --}}
                            <span dir="ltr">{{ $selectedInstance->start_date->format('d M, Y') }} - {{ $selectedInstance->end_date->format('d M, Y') }}</span>
                        @elseif($instances->count() > 1)
                            <span>متوفرة في {{ $instances->count() }} مواعيد</span>
                        @else
                            <span>{{ $template->duration_days ? $template->duration_days . ' أيام' : 'لا توجد مواعيد متاحة' }}</span>
                        @endif
                    </div>
                </div>
            </div>
            
            {{-- Quick Price Action (Mobile Top, Desktop Right) --}}
            <div class="glass-panel p-4 rounded-3xl shrink-0 text-center md:text-right hidden md:block">
                <p class="text-xs text-slate-400 mb-1">السعر يبدأ من</p>
                <div class="text-3xl font-black text-zatara-blue">
                    {{ number_format($template->starting_price) }} <span class="text-base font-medium">{{ $template->currency ?? 'USD' }}</span>
                </div>
            </div>
        </div>

        {{-- MASONRY GALLERY --}}
        @php
            // Storefront redesign Phase B (Section D): every gallery image used to serve the raw
            // original at whatever resolution it was uploaded. $mainImg is this page's above-the-
            // fold LCP element (stays eager, no lazy-load); $img2/$img3/$img4 are secondary
            // thumbnails and get loading="lazy" + a real srcset via the 'card'/'card-2x'
            // conversions instead.
            $coverMedia = $template->getFirstMedia('cover');
            $galleryMedia = $template->getMedia('gallery') ?? collect();
            $mediaList = $coverMedia ? collect([$coverMedia])->merge($galleryMedia) : $galleryMedia;

            $urlFor = fn ($media) => $media ? ($media->getUrl('card') ?: $media->getUrl()) : null;
            $url2xFor = fn ($media) => $media ? $media->getUrl('card-2x') : null;

            $mainImg = $mediaList->count() > 0 ? $urlFor($mediaList[0]) : asset('images/placeholder.jpg');
            $img2 = $mediaList->count() > 1 ? $urlFor($mediaList[1]) : null;
            $img2_2x = $mediaList->count() > 1 ? $url2xFor($mediaList[1]) : null;
            $img3 = $mediaList->count() > 2 ? $urlFor($mediaList[2]) : null;
            $img3_2x = $mediaList->count() > 2 ? $url2xFor($mediaList[2]) : null;
            $img4 = $mediaList->count() > 3 ? $urlFor($mediaList[3]) : null;
            $img4_2x = $mediaList->count() > 3 ? $url2xFor($mediaList[3]) : null;

            $count = $mediaList->count();
        @endphp
        
        <div class="relative group">
            @if($count == 0)
                {{-- No cover/gallery media at all -- public/images/placeholder.jpg (the previous
                     fallback here) doesn't actually exist in the repo, so this used to render a
                     broken image icon instead of a graceful empty state. Matches the same
                     gradient+icon placeholder the catalog card already uses. --}}
                <div class="w-full h-[60vh] rounded-[2.5rem] overflow-hidden relative">
                    <x-trip-cover-placeholder :seed="$template->id" />
                </div>
            @elseif($count == 1)
                <div class="w-full h-[60vh] rounded-[2.5rem] overflow-hidden relative">
                    <img src="{{ $mainImg }}" class="w-full h-full object-cover transition-transform duration-700 hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                </div>
            @elseif($count == 2)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 h-[60vh] rounded-[2.5rem] overflow-hidden relative">
                    <div class="relative overflow-hidden group">
                        <img src="{{ $mainImg }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                    </div>
                    <div class="relative overflow-hidden group bg-slate-100 hidden md:block">
                        <img src="{{ $img2 }}"
                             @if($img2_2x) srcset="{{ $img2 }} 800w, {{ $img2_2x }} 1200w" sizes="50vw" @endif
                             loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                    </div>
                </div>
            @elseif($count == 3)
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 h-[60vh] rounded-[2.5rem] overflow-hidden relative">
                    <div class="md:col-span-2 relative overflow-hidden group">
                        <img src="{{ $mainImg }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                    </div>
                    <div class="hidden md:grid grid-rows-2 gap-4">
                        <div class="relative overflow-hidden group bg-slate-100">
                            <img src="{{ $img2 }}"
                             @if($img2_2x) srcset="{{ $img2 }} 800w, {{ $img2_2x }} 1200w" sizes="50vw" @endif
                             loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                        </div>
                        <div class="relative overflow-hidden group bg-slate-100">
                            <img src="{{ $img3 }}"
                             @if($img3_2x) srcset="{{ $img3 }} 800w, {{ $img3_2x }} 1200w" sizes="50vw" @endif
                             loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                        </div>
                    </div>
                </div>
            @else
                <div class="grid grid-cols-4 grid-rows-2 gap-4 h-[60vh] rounded-[2.5rem] overflow-hidden">
                    <div class="col-span-4 md:col-span-2 row-span-2 relative overflow-hidden group">
                        <img src="{{ $mainImg }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                    </div>
                    <div class="col-span-2 md:col-span-1 row-span-1 relative overflow-hidden group bg-slate-100 hidden md:block">
                        <img src="{{ $img2 }}"
                             @if($img2_2x) srcset="{{ $img2 }} 800w, {{ $img2_2x }} 1200w" sizes="50vw" @endif
                             loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                    </div>
                    <div class="col-span-2 md:col-span-1 row-span-1 relative overflow-hidden group bg-slate-100 hidden md:block">
                        <img src="{{ $img3 }}"
                             @if($img3_2x) srcset="{{ $img3 }} 800w, {{ $img3_2x }} 1200w" sizes="50vw" @endif
                             loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                    </div>
                    <div class="col-span-4 md:col-span-2 row-span-1 relative overflow-hidden group bg-slate-100 hidden md:block">
                        <img src="{{ $img4 }}"
                             @if($img4_2x) srcset="{{ $img4 }} 800w, {{ $img4_2x }} 1200w" sizes="50vw" @endif
                             loading="lazy"
                             class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="{{ $template->title ?? 'صورة الرحلة' }}">
                    </div>
                </div>
            @endif

            {{-- View All Photos Button --}}
            @if($count > 1)
                <button @click="showGallery = true" class="absolute bottom-4 right-4 glass-panel px-6 py-2 rounded-xl font-bold text-zatara-blue hover:bg-white transition-colors flex items-center gap-2 shadow-lg">
                    <span class="material-symbols-outlined">photo_library</span>
                    شاهد جميع الصور ({{ $count }})
                </button>
            @endif
        </div>
    </section>

    {{-- CONTENT & STICKY BOOKING WIDGET --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32 lg:pb-0">
        <div class="flex flex-col lg:flex-row gap-12 relative">
            
            {{-- MAIN CONTENT (Left Side) --}}
            <div class="flex-1">
                {{-- Quick Info Bar -- matches the Stitch mockup's fact strip
                     (stich_with_google_store/stitch_admin_panel_arabic_rebranding (4)), but only
                     the two facts this app actually has real data for: duration (computed from
                     the selected instance's own dates, not a nonexistent template-level field)
                     and trip type (an existing, already-populated enum). The mockup also shows a
                     "departure city" and "hotel level" fact -- there's no real field backing
                     either one on TripTemplate/TripInstance (PickupRoute/PickupPoint model local
                     transport pickup points, not a trip-level origin city; hotel star level is
                     package-specific, not a single trip-wide fact), so neither is faked here. --}}
                @if($selectedInstance || $template->trip_type)
                    <div class="flex flex-wrap gap-6 bg-slate-50 border border-slate-100 rounded-2xl p-6 mb-10">
                        @if($selectedInstance)
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-zatara-blue text-2xl">schedule</span>
                                <div>
                                    <p class="text-xs text-slate-400 font-medium">المدة</p>
                                    <p class="font-bold text-zatara-blue">
                                        {{ $selectedInstance->start_date->diffInDays($selectedInstance->end_date) + 1 }} أيام
                                        / {{ $selectedInstance->start_date->diffInDays($selectedInstance->end_date) }} ليالٍ
                                    </p>
                                </div>
                            </div>
                        @endif
                        @if($template->trip_type)
                            <div class="hidden sm:block w-px bg-slate-200"></div>
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-zatara-blue text-2xl">public</span>
                                <div>
                                    <p class="text-xs text-slate-400 font-medium">نوع الرحلة</p>
                                    <p class="font-bold text-zatara-blue">{{ $template->trip_type->getLabel() }}</p>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- About the Trip --}}
                <div class="mb-12">
                    <h2 class="text-2xl font-bold text-zatara-blue mb-4">عن الرحلة</h2>
                    <div class="text-slate-600 font-light leading-loose text-lg prose">
                        {!! $template->description ?? 'لا توجد تفاصيل إضافية مسجلة لهذه الرحلة حتى الآن.' !!}
                    </div>
                </div>

                {{-- Storefront redesign Phase F, item 2: per-category price breakdown, surfaced
                     here instead of staying hidden until checkout Step 2. Reuses
                     tripPassengerCategories exactly as already loaded for $selectedInstance --
                     no new query, no change to CreateBookingService/checkout pricing logic. --}}
                @if($selectedInstance && $selectedInstance->tripPassengerCategories->isNotEmpty())
                    <div class="mb-12">
                        <h2 class="text-2xl font-bold text-zatara-blue mb-6">تفاصيل الأسعار</h2>
                        <div class="bg-white border border-slate-100 rounded-2xl overflow-hidden">
                            <table class="w-full text-right">
                                <thead>
                                    <tr class="bg-slate-50 border-b border-slate-100">
                                        <th class="px-6 py-3 text-sm font-bold text-slate-500">الفئة</th>
                                        <th class="px-6 py-3 text-sm font-bold text-slate-500">السعر (للشخص)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($selectedInstance->tripPassengerCategories as $category)
                                        <tr class="border-b border-slate-100 last:border-b-0 transition-colors duration-200 hover:bg-slate-50">
                                            <td class="px-6 py-4 font-medium text-slate-700">{{ $category->name }}</td>
                                            <td class="px-6 py-4 font-bold text-zatara-blue">
                                                @if((float) $category->price > 0)
                                                    {{ number_format($category->price) }} {{ $template->currency ?? 'USD' }}
                                                @else
                                                    <span class="text-teal-600">مجاناً</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- Interactive Itinerary Timeline --}}
                <div class="mb-12">
                    <h2 class="text-2xl font-bold text-zatara-blue mb-8">مسار الرحلة الممتع</h2>
                    
                    @if($template->itinerary_data && is_array($template->itinerary_data) && count($template->itinerary_data) > 0)
                        {{-- Each day wrapped in its own bordered card (matching the Stitch
                             mockup's per-day cards) rather than bare text against the timeline
                             line -- the line/dot alone read as noticeably sparser than the
                             mockup's actual density. First day's dot in the gold accent color
                             (matches the mockup's day-1 accent), the rest in Sapphire Blue. --}}
                        <div class="relative border-r-2 border-zatara-blue/10 pr-8 space-y-6">
                            @foreach($template->itinerary_data as $index => $day)
                                <div class="relative">
                                    <div class="absolute -right-11 w-6 h-6 rounded-full {{ $index === 0 ? 'bg-zatara-gold' : 'bg-zatara-blue' }} border-4 border-white flex items-center justify-center shadow-md text-white text-xs font-bold">
                                        {{ $index + 1 }}
                                    </div>
                                    <div class="bg-white border border-slate-100 rounded-2xl p-5 shadow-sm transition-all duration-300 hover:shadow-md hover:border-zatara-blue/20 hover:-translate-y-0.5">
                                        <h3 class="text-lg font-bold text-zatara-blue mb-2">{{ $day['title'] ?? 'اليوم ' . ($index + 1) }}</h3>
                                        <p class="text-slate-500 font-light leading-relaxed">
                                            {{ $day['description'] ?? '' }}
                                        </p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="bg-slate-50 rounded-2xl p-8 text-center text-slate-400">
                            لم يتم إضافة مسار تفصيلي لهذه الرحلة بعد.
                        </div>
                    @endif
                </div>

                {{-- Destination Map -- new fields (migration
                     2026_09_09_000001_add_destination_coordinates_to_trip_templates). Hidden
                     entirely (not a broken/blank map) until an admin fills in both coordinates.
                     OpenStreetMap's export/embed.html iframe needs no API key/credential at all,
                     unlike Google Maps -- the right default here since nothing else in this app
                     already has a paid mapping credential to reuse. --}}
                @if($template->destination_latitude !== null && $template->destination_longitude !== null)
                    @php
                        $lat = (float) $template->destination_latitude;
                        $lng = (float) $template->destination_longitude;
                        // A small fixed-size box around the point -- wide enough to show real
                        // surrounding context (city/coastline/etc.) without the marker looking
                        // lost on an overly zoomed-out map.
                        $bbox = ($lng - 0.05) . '%2C' . ($lat - 0.05) . '%2C' . ($lng + 0.05) . '%2C' . ($lat + 0.05);
                    @endphp
                    <div class="mb-12">
                        <h2 class="text-2xl font-bold text-zatara-blue mb-6">الموقع على الخريطة</h2>
                        <div class="rounded-2xl overflow-hidden border border-slate-100 h-80">
                            <iframe
                                class="w-full h-full"
                                style="border: 0"
                                loading="lazy"
                                src="https://www.openstreetmap.org/export/embed.html?bbox={{ $bbox }}&marker={{ $lat }}%2C{{ $lng }}&layer=mapnik"
                                title="موقع {{ $template->title }} على الخريطة">
                            </iframe>
                        </div>
                    </div>
                @endif

                {{-- Includes / Excludes -- new fields (migration
                     2026_09_07_000001_add_includes_excludes_to_trip_templates), matching the
                     Stitch mockup's "يشمل / لا يشمل" two-column section. Hidden entirely (not
                     shown with a placeholder/empty state) until an agency admin actually fills
                     these in via TripTemplateResource -- no fake content. --}}
                @if(!empty($template->includes) || !empty($template->excludes))
                    <div class="mb-12 grid grid-cols-1 sm:grid-cols-2 gap-6">
                        @if(!empty($template->excludes))
                            <div class="bg-white border border-slate-100 rounded-2xl p-6 transition-shadow duration-300 hover:shadow-md">
                                <h3 class="flex items-center gap-2 text-lg font-bold text-zatara-red mb-4">
                                    <span class="material-symbols-outlined">cancel</span>
                                    لا يشمل
                                </h3>
                                <ul class="space-y-2">
                                    @foreach($template->excludes as $item)
                                        <li class="text-slate-600 text-sm flex items-start gap-2">
                                            <span class="text-slate-300 mt-1">•</span>
                                            <span>{{ $item }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        @if(!empty($template->includes))
                            <div class="bg-white border border-slate-100 rounded-2xl p-6 transition-shadow duration-300 hover:shadow-md">
                                <h3 class="flex items-center gap-2 text-lg font-bold text-zatara-success mb-4">
                                    <span class="material-symbols-outlined">check_circle</span>
                                    يشمل
                                </h3>
                                <ul class="space-y-2">
                                    @foreach($template->includes as $item)
                                        <li class="text-slate-600 text-sm flex items-start gap-2">
                                            <span class="text-slate-300 mt-1">•</span>
                                            <span>{{ $item }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- STICKY BOOKING WIDGET (Right Side) --}}
            <div class="w-full lg:w-96 shrink-0 relative">
                <div class="sticky top-32 bg-white rounded-3xl overflow-hidden border border-slate-100 shadow-2xl shadow-zatara-blue/5">

                    @if($instances->isEmpty())
                        <div class="text-center py-8 px-6">
                            <span class="material-symbols-outlined text-5xl text-slate-300 mb-4">event_busy</span>
                            <h3 class="text-xl font-bold text-zatara-blue mb-2">لا توجد رحلات قادمة</h3>
                            <p class="text-slate-500 text-sm mb-6">سنقوم بتحديث المواعيد قريباً.</p>
                            <button class="btn-secondary w-full py-3">أعلمني عند توفر مقاعد</button>
                        </div>
                    @else
                        {{-- Solid Sapphire Blue price header, matching the mockup's bg-primary
                             block (stich_with_google_store/stitch_admin_panel_arabic_rebranding
                             (4)) -- previously a plain glass-panel treatment shared with the rest
                             of the card. --}}
                        <div class="bg-zatara-blue text-white p-6">
                            <p class="text-sm text-white/70 mb-1">يبدأ من</p>
                            <div class="flex items-baseline gap-2 flex-wrap">
                                <span class="text-4xl font-black">
                                    @if($hasVariablePricing && $selectedInstance)
                                        {{ number_format($selectedInstance->price_override ? $selectedInstance->price_override_amount : ($template->starting_price)) }}
                                    @else
                                        {{ number_format($template->starting_price) }}
                                    @endif
                                </span>
                                <span class="text-sm font-medium text-white/80">{{ $template->currency ?? 'USD' }} / للشخص</span>
                            </div>
                            @if($selectedInstance && $selectedInstance->remaining_seats <= 10 && $selectedInstance->remaining_seats > 0)
                                <p class="text-xs font-medium mt-3 bg-white/15 py-1 px-3 rounded-full inline-block">
                                    <span class="material-symbols-outlined text-[14px] align-middle">local_fire_department</span>
                                    متبقي {{ $selectedInstance->remaining_seats }} مقاعد فقط!
                                </p>
                            @endif
                        </div>

                        <div class="p-6">
                        <div class="space-y-4 mb-6">
                            {{-- Date Selector --}}
                            @if($instances->count() == 1)
                                <div class="bg-slate-50 border border-slate-200 rounded-2xl p-4 flex justify-between items-center">
                                    <div>
                                        <p class="text-xs text-slate-400 font-medium">تاريخ المغادرة</p>
                                        <p class="font-bold text-zatara-blue">{{ $selectedInstance->start_date->format('d M, Y') }}</p>
                                    </div>
                                    <span class="material-symbols-outlined text-slate-400">event_available</span>
                                </div>
                            @else
                                <div>
                                    <label class="block text-xs text-slate-500 font-bold mb-2">اختر تاريخ المغادرة</label>
                                    <select wire:model.live="selectedInstanceId" class="w-full bg-white border border-slate-200 rounded-2xl px-4 py-3 font-bold text-zatara-blue focus:ring-2 focus:ring-zatara-blue focus:border-transparent outline-none">
                                        @foreach($instances as $inst)
                                            <option value="{{ $inst->id }}">
                                                {{ $inst->start_date->format('d M, Y') }} 
                                                @if($hasVariablePricing)
                                                    - {{ number_format($inst->price_override ? $inst->price_override_amount : ($template->starting_price)) }} {{ $template->currency ?? 'USD' }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            
                            {{-- Traveler Count Stepper -- was pure decoration: styled to look
                                 clickable (cursor-pointer, hover border) but had no @click/wire:click
                                 handler at all, so nothing happened on click and the "1 بالغ" text
                                 never changed no matter what a customer did. Alpine-only (no
                                 Livewire round-trip needed for a plain increment/decrement); the
                                 chosen count flows into checkout via the CTA link's ?travelers=
                                 query param below, which CheckoutWizard::mount() now reads to
                                 pre-add that many passenger rows instead of always just one. --}}
                            <div class="bg-white border border-slate-200 rounded-2xl p-4">
                                <p class="text-xs text-slate-400 font-medium mb-2">عدد المسافرين</p>
                                <div class="flex items-center justify-between border border-slate-200 rounded-xl p-1">
                                    <button type="button"
                                            @click="travelerCount = Math.max(1, travelerCount - 1)"
                                            :disabled="travelerCount <= 1"
                                            class="w-10 h-10 rounded-lg bg-slate-50 hover:bg-slate-100 text-zatara-blue flex items-center justify-center transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                                        <span class="material-symbols-outlined">remove</span>
                                    </button>
                                    <span class="font-bold text-zatara-blue text-lg" x-text="travelerCount"></span>
                                    <button type="button"
                                            @click="travelerCount = Math.min(maxTravelers, travelerCount + 1)"
                                            :disabled="travelerCount >= maxTravelers"
                                            class="w-10 h-10 rounded-lg bg-slate-50 hover:bg-slate-100 text-zatara-blue flex items-center justify-center transition-colors disabled:opacity-40 disabled:cursor-not-allowed">
                                        <span class="material-symbols-outlined">add</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        @if($this->availablePackages->count() > 0)
                            <div class="mb-4">
                                <p class="text-xs text-slate-400 font-bold mb-3">اختر الباقة</p>
                                <div class="space-y-2">
                                    @foreach($this->availablePackages as $package)
                                        <label wire:key="pkg-{{ $package->id }}"
                                               class="flex items-center gap-3 p-3 rounded-2xl border cursor-pointer transition-all
                                                      {{ $selectedPackageId == $package->id 
                                                         ? 'border-zatara-blue bg-zatara-blue/5' 
                                                         : 'border-slate-200 hover:border-zatara-blue/40' }}
                                                      {{ $package->remaining_seats <= 0 ? 'opacity-40 cursor-not-allowed' : '' }}">
                                            <input type="radio" 
                                                   wire:model.live="selectedPackageId" 
                                                   value="{{ $package->id }}"
                                                   {{ $package->remaining_seats <= 0 ? 'disabled' : '' }}
                                                   class="sr-only">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-bold text-zatara-blue text-sm">
                                                        {{ $package->hotel_name ?? $package->name }}
                                                    </span>
                                                    @if($package->stars)
                                                        <span class="text-zatara-gold text-xs">
                                                            {{ str_repeat('★', $package->stars) }}
                                                        </span>
                                                    @endif
                                                </div>
                                                @if($package->room_type || $package->meal_plan)
                                                    <p class="text-xs text-slate-400 mt-0.5">
                                                        {{ $package->room_type }} 
                                                        {{ $package->room_type && $package->meal_plan ? '·' : '' }} 
                                                        {{ $package->meal_plan }}
                                                    </p>
                                                @endif
                                                @if($package->remaining_seats <= 5 && $package->remaining_seats > 0)
                                                    <p class="text-xs text-red-500 mt-0.5">
                                                        ⚠ متبقي {{ $package->remaining_seats }} فقط
                                                    </p>
                                                @elseif($package->remaining_seats <= 0)
                                                    <p class="text-xs text-slate-400 mt-0.5">مكتملة</p>
                                                @endif
                                            </div>
                                            <div class="shrink-0 text-right">
                                                @if($package->price_adjustment > 0)
                                                    <span class="text-zatara-gold font-black text-sm">
                                                        +{{ number_format($package->price_adjustment) }} {{ $template->currency ?? 'USD' }}
                                                    </span>
                                                @elseif($package->price_adjustment == 0)
                                                    <span class="text-green-600 font-bold text-xs">مشمول</span>
                                                @else
                                                    <span class="text-green-600 font-bold text-xs">
                                                        -{{ number_format(abs($package->price_adjustment)) }} {{ $template->currency ?? 'USD' }}
                                                    </span>
                                                @endif
                                            </div>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            
                            <div class="border-t border-slate-100 pt-4 mb-4">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-slate-500">السعر النهائي</span>
                                    <span class="text-2xl font-black text-zatara-blue">
                                        {{ number_format($this->finalPrice) }} {{ $template->currency ?? 'USD' }}
                                    </span>
                                </div>
                            </div>
                        @endif

                        @if($selectedInstance && $selectedInstance->remaining_seats > 0)
                            {{-- x-bind:href appends the live traveler count -- a plain Blade href
                                 would freeze it at whatever value was true on page load, since
                                 the stepper above is Alpine-only (no Livewire round-trip). The
                                 separator is computed server-side rather than always assuming
                                 '&': route() omits the package param entirely (no leading '?' at
                                 all) whenever no package is selected, so a hardcoded '&' produced
                                 a malformed "...checkout/48&travelers=3" URL with no '?' -- caught
                                 during live-verification, not by the Livewire test below (which
                                 asserts CheckoutWizard's own query-reading, not this href string). --}}
                            @php
                                $checkoutBaseUrl = route('storefront.checkout', ['tenant' => $tenant->slug, 'tripInstance' => $selectedInstance->id, 'package' => $selectedPackageId]);
                                $checkoutUrlSeparator = str_contains($checkoutBaseUrl, '?') ? '&' : '?';
                            @endphp
                            <a href="{{ $checkoutBaseUrl }}"
                               x-bind:href="'{{ $checkoutBaseUrl }}{{ $checkoutUrlSeparator }}travelers=' + travelerCount"
                               class="btn-secondary w-full block text-center text-lg shadow-xl shadow-zatara-gold/20 animate-pulse hover:animate-none py-3">
                                بدء إجراءات الحجز
                            </a>
                            <p class="text-center text-xs text-slate-400 font-light mt-4">
                                لن يتم الخصم من بطاقتك الآن.
                            </p>
                        @else
                            <button disabled class="w-full block text-center text-lg px-6 py-3 font-bold text-slate-500 bg-slate-200 rounded-2xl cursor-not-allowed">
                                مكتملة العدد
                            </button>
                        @endif
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </section>

    {{-- Lightbox Modal --}}
    <div x-show="showGallery" style="display: none;" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/95 backdrop-blur-sm"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        
        <button @click="showGallery = false" class="absolute top-6 right-6 text-white hover:text-zatara-gold transition-colors z-50">
            <span class="material-symbols-outlined text-4xl">close</span>
        </button>

        <div class="w-full h-full p-12 overflow-y-auto">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 max-w-7xl mx-auto mt-10">
                @foreach($mediaList as $media)
                    <div class="rounded-2xl overflow-hidden shadow-2xl">
                        {{-- This whole modal is x-show hidden until the customer opens it, so
                             every image in it is a genuine deferred-load candidate. --}}
                        <img src="{{ $media->getUrl('card') ?: $media->getUrl() }}" loading="lazy" class="w-full h-auto object-cover hover:scale-105 transition-transform duration-500" alt="Trip Image">
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
