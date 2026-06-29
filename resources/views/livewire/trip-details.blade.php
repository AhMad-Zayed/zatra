<div>
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
            <span class="text-zatara-blue">{{ $instance->tripTemplate->title }}</span>
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
                    {{ $instance->tripTemplate->title }}
                </h1>
                <div class="flex items-center gap-6 text-slate-500 font-medium">
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-zatara-gold">calendar_month</span>
                        <span>{{ $instance->start_date->format('d M, Y') }} - {{ $instance->end_date->format('d M, Y') }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="material-symbols-outlined text-zatara-gold">group</span>
                        <span>مجموعة (حتى {{ $instance->capacity }} شخص)</span>
                    </div>
                </div>
            </div>
            
            {{-- Quick Price Action (Mobile Top, Desktop Right) --}}
            <div class="glass-panel p-4 rounded-3xl shrink-0 text-center md:text-right hidden md:block">
                <p class="text-xs text-slate-400 mb-1">السعر يبدأ من</p>
                <div class="text-3xl font-black text-zatara-blue">
                    {{ number_format($instance->tripPassengerCategories->min('price') ?? $instance->tripTemplate->base_price) }} <span class="text-base font-medium">دولار</span>
                </div>
            </div>
        </div>

        {{-- MASONRY GALLERY --}}
        @php
            $media = collect([
                $instance->getFirstMediaUrl('trip_images'),
                $instance->tripTemplate->getFirstMediaUrl('trip_images')
            ])->filter()->first();
            
            $mainImg = $media ?: 'https://images.unsplash.com/photo-1506929562872-bb421503ef21?w=1200&q=80';
            $img2 = $media ?: 'https://images.unsplash.com/photo-1544550581-5f7ceaf7f992?w=600&q=80';
            $img3 = $media ?: 'https://images.unsplash.com/photo-1499793983690-e29da59ef1c2?w=600&q=80';
            $img4 = $media ?: 'https://images.unsplash.com/photo-1510414842594-a61c69b5ae57?w=800&q=80';
        @endphp
        <div class="grid grid-cols-4 grid-rows-2 gap-4 h-[60vh] rounded-[2.5rem] overflow-hidden">
            <div class="col-span-4 md:col-span-2 row-span-2 relative group">
                <img src="{{ $mainImg }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="Main Destination">
            </div>
            <div class="col-span-2 md:col-span-1 row-span-1 relative group">
                <img src="{{ $img2 }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="Destination View">
            </div>
            <div class="col-span-2 md:col-span-1 row-span-1 relative group">
                <img src="{{ $img3 }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="Activity">
            </div>
            <div class="col-span-4 md:col-span-2 row-span-1 relative group">
                <img src="{{ $img4 }}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" alt="Hotel">
                {{-- View All Photos Button --}}
                <button class="absolute bottom-4 right-4 glass-panel px-6 py-2 rounded-xl font-bold text-zatara-blue hover:bg-white transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined">photo_library</span>
                    شاهد جميع الصور
                </button>
            </div>
        </div>
    </section>

    {{-- CONTENT & STICKY BOOKING WIDGET --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32">
        <div class="flex flex-col lg:flex-row gap-12 relative">
            
            {{-- MAIN CONTENT (Left Side) --}}
            <div class="flex-1">
                {{-- About the Trip --}}
                <div class="mb-12">
                    <h2 class="text-2xl font-bold text-zatara-blue mb-4">عن الرحلة</h2>
                    <div class="text-slate-600 font-light leading-loose text-lg prose">
                        {!! $instance->tripTemplate->description ?? 'لا توجد تفاصيل إضافية مسجلة لهذه الرحلة حتى الآن.' !!}
                    </div>
                </div>

                {{-- Interactive Itinerary Timeline --}}
                <div class="mb-12">
                    <h2 class="text-2xl font-bold text-zatara-blue mb-8">مسار الرحلة الممتع</h2>
                    
                    <div class="relative border-r-2 border-zatara-blue/10 pr-8 space-y-12">
                        
                        {{-- Day 1 --}}
                        <div class="relative">
                            <div class="absolute -right-11 w-6 h-6 rounded-full bg-zatara-gold border-4 border-white flex items-center justify-center shadow-md"></div>
                            <h3 class="text-xl font-bold text-zatara-blue mb-2">اليوم الأول: الوصول والاستقبال</h3>
                            <p class="text-slate-500 font-light leading-relaxed">
                                الاستقبال في المطار من قبل مندوبنا، والتوجه إلى الفندق الفاخر للاستراحة بعد عناء السفر. في المساء، جولة حرة خفيفة للتعرف على محيط الفندق.
                            </p>
                            <div class="mt-4 flex gap-4">
                                <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-lg text-sm font-medium flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">flight_land</span> وصول المطار</span>
                                <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-lg text-sm font-medium flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">hotel</span> إقامة فندقية 5 نجوم</span>
                            </div>
                        </div>

                        {{-- Day 2 --}}
                        <div class="relative">
                            <div class="absolute -right-11 w-6 h-6 rounded-full bg-zatara-blue border-4 border-white flex items-center justify-center shadow-md"></div>
                            <h3 class="text-xl font-bold text-zatara-blue mb-2">اليوم الثاني: جولة المدينة التاريخية</h3>
                            <p class="text-slate-500 font-light leading-relaxed">
                                بعد الإفطار، تبدأ جولتنا لاستكشاف أهم المعالم التاريخية والثقافية للمدينة مع مرشد سياحي مختص. تناول الغداء في مطعم تقليدي.
                            </p>
                            <div class="mt-4 flex gap-4">
                                <span class="bg-slate-100 text-slate-600 px-3 py-1 rounded-lg text-sm font-medium flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">museum</span> المعالم التاريخية</span>
                            </div>
                        </div>

                        {{-- Final Day --}}
                        <div class="relative">
                            <div class="absolute -right-11 w-6 h-6 rounded-full bg-slate-300 border-4 border-white flex items-center justify-center shadow-md"></div>
                            <h3 class="text-xl font-bold text-slate-600 mb-2">يوم المغادرة</h3>
                            <p class="text-slate-500 font-light leading-relaxed">
                                التوجه إلى المطار للعودة إلى أرض الوطن محملين بأجمل الذكريات.
                            </p>
                        </div>

                    </div>
                </div>
                
                {{-- Addons --}}
                @if($instance->tripAddons->count() > 0)
                <div class="mb-12">
                    <h2 class="text-2xl font-bold text-zatara-blue mb-4">الإضافات المتاحة</h2>
                    <ul class="list-disc list-inside text-slate-600 font-light leading-loose text-lg">
                        @foreach($instance->tripAddons as $addon)
                            <li>{{ $addon->name }} - {{ number_format($addon->price) }} دولار</li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>

            {{-- STICKY BOOKING WIDGET (Right Side) --}}
            <div class="w-full lg:w-96 shrink-0 relative">
                <div class="sticky top-32 glass-panel rounded-3xl p-6 shadow-2xl shadow-zatara-blue/5">
                    
                    <div class="text-center mb-6 border-b border-slate-100 pb-6">
                        <p class="text-sm text-slate-400 mb-1">احجز مقعدك الآن</p>
                        <div class="text-4xl font-black text-zatara-blue">
                            {{ number_format($instance->tripPassengerCategories->min('price') ?? $instance->tripTemplate->base_price) }} <span class="text-lg font-medium text-slate-500">دولار</span>
                        </div>
                        <p class="text-xs text-zatara-red font-medium mt-2 bg-zatara-red/10 py-1 px-3 rounded-full inline-block">
                            <span class="material-symbols-outlined text-[14px] align-middle">local_fire_department</span>
                            مقاعد محدودة متبقية!
                        </p>
                    </div>

                    <div class="space-y-4 mb-6">
                        {{-- Date Selector --}}
                        <div class="bg-white border border-slate-200 rounded-2xl p-4 flex justify-between items-center cursor-pointer hover:border-zatara-blue transition-colors">
                            <div>
                                <p class="text-xs text-slate-400 font-medium">تاريخ المغادرة</p>
                                <p class="font-bold text-zatara-blue">{{ $instance->start_date->format('d M, Y') }}</p>
                            </div>
                            <span class="material-symbols-outlined text-zatara-gold">edit_calendar</span>
                        </div>
                        
                        {{-- Guests Selector --}}
                        <div class="bg-white border border-slate-200 rounded-2xl p-4 flex justify-between items-center cursor-pointer hover:border-zatara-blue transition-colors">
                            <div>
                                <p class="text-xs text-slate-400 font-medium">المسافرين</p>
                                <p class="font-bold text-zatara-blue">1 بالغ</p>
                            </div>
                            <span class="material-symbols-outlined text-zatara-gold">person_add</span>
                        </div>
                    </div>

                    @if($instance->remaining_seats > 0)
                        <a href="{{ route('storefront.checkout', ['tenant' => $tenant->slug, 'tripInstance' => $instance->id]) }}" class="btn-secondary w-full block text-center text-lg shadow-xl shadow-zatara-gold/20 animate-pulse hover:animate-none">
                            بدء إجراءات الحجز
                        </a>
                        <p class="text-center text-xs text-slate-400 font-light mt-4">
                            لن يتم الخصم من بطاقتك الآن.
                        </p>
                    @else
                        <button disabled class="w-full block text-center text-lg px-6 py-4 font-bold text-slate-500 bg-slate-200 rounded-2xl cursor-not-allowed">
                            مكتملة العدد (Sold Out)
                        </button>
                        <p class="text-center text-xs text-slate-400 font-light mt-4">
                            للأسف، لا توجد مقاعد شاغرة لهذه الرحلة.
                        </p>
                    @endif
                </div>
            </div>

        </div>
    </section>

</div>
