<div>
    {{-- ========================== 
         ZATARA GLOBAL UI (2026)
         Aesthetic: Aerodynamic Clarity, Glassmorphism, Premium Travel
         ========================== --}}

    {{-- CINEMATIC HERO SECTION (Rounded Container) --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
        <div class="relative w-full h-[85vh] rounded-[2.5rem] overflow-hidden shadow-2xl shadow-zatara-blue/10 flex items-center justify-center">
            
            {{-- Background Image with slow pan --}}
            <img src="https://images.unsplash.com/photo-1544550581-5f7ceaf7f992?w=2000&q=80" alt="Tropical Island" class="absolute inset-0 w-full h-full object-cover animate-slowPan" />
            
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

            {{-- Glassmorphism Search Bar --}}
            <div class="absolute bottom-12 left-1/2 -translate-x-1/2 w-11/12 max-w-4xl z-20">
                <div class="glass-panel rounded-3xl p-4 flex flex-col md:flex-row items-center gap-4">
                    
                    <div class="flex-1 w-full relative">
                        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-zatara-blue/60">location_on</span>
                        <input type="text" placeholder="الوجهة (مثال: سويسرا، دبي)" class="w-full bg-transparent border-none text-slate-800 text-lg font-medium pr-12 pl-4 py-3 focus:ring-0 placeholder:text-slate-400 placeholder:font-light" />
                    </div>
                    
                    <div class="hidden md:block w-[1px] h-10 bg-zatara-blue/10"></div>
                    
                    <div class="flex-1 w-full relative">
                        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-zatara-blue/60">calendar_month</span>
                        <input type="text" placeholder="تاريخ السفر" class="w-full bg-transparent border-none text-slate-800 text-lg font-medium pr-12 pl-4 py-3 focus:ring-0 placeholder:text-slate-400 placeholder:font-light" />
                    </div>

                    <div class="hidden md:block w-[1px] h-10 bg-zatara-blue/10"></div>

                    <div class="flex-1 w-full relative">
                        <span class="material-symbols-outlined absolute right-4 top-1/2 -translate-y-1/2 text-zatara-blue/60">group</span>
                        <input type="text" placeholder="الضيوف (2 بالغين)" class="w-full bg-transparent border-none text-slate-800 text-lg font-medium pr-12 pl-4 py-3 focus:ring-0 placeholder:text-slate-400 placeholder:font-light" />
                    </div>

                    <button class="btn-primary w-full md:w-auto px-10 py-4 text-lg font-bold flex items-center justify-center gap-2">
                        <span class="material-symbols-outlined">search</span>
                        بحث
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- FLIGHT PATH DIVIDER --}}
    <div class="max-w-4xl mx-auto my-24 flex items-center gap-4 opacity-50 px-4">
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
            <div class="flex gap-2">
                <button class="w-12 h-12 rounded-full border border-slate-200 flex items-center justify-center text-slate-400 hover:text-zatara-blue hover:border-zatara-blue transition-colors">
                    <span class="material-symbols-outlined">arrow_forward</span>
                </button>
                <button class="w-12 h-12 rounded-full bg-zatara-blue flex items-center justify-center text-white hover:bg-zatara-blue/90 shadow-lg shadow-zatara-blue/20 transition-all">
                    <span class="material-symbols-outlined">arrow_back</span>
                </button>
            </div>
        </div>
    </section>

    {{-- BENTO GRID TRIP CARDS --}}
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32">
        @if($tripInstances->isEmpty())
            <div class="glass-panel rounded-3xl p-20 text-center">
                <span class="material-symbols-outlined text-6xl text-slate-300 mb-4 block">luggage</span>
                <p class="text-slate-500 font-medium text-xl">نقوم بتجهيز باقات استثنائية قريباً.</p>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                @foreach($tripInstances as $instance)
                    <a href="{{ route('storefront.trip.details', ['tenant' => $tenant->slug, 'tripInstance' => $instance->id]) }}" class="group block relative rounded-3xl p-3 bg-white border border-slate-100 shadow-[0_4px_20px_rgba(0,0,0,0.03)] hover:shadow-[0_20px_40px_rgba(43,50,128,0.08)] transition-all duration-500 hover:-translate-y-2">
                        
                        {{-- Image Container --}}
                        <div class="relative w-full aspect-[4/3] rounded-[1.25rem] overflow-hidden mb-5">
                            @php
                                $mediaUrl = collect([
                                    $instance->getFirstMediaUrl('trip_images'),
                                    $instance->tripTemplate->getFirstMediaUrl('trip_images')
                                ])->filter()->first() ?: 'https://images.unsplash.com/photo-1506929562872-bb421503ef21?w=800&q=80';
                            @endphp
                            <img src="{{ $mediaUrl }}" alt="Trip" class="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-110" />
                            
                            {{-- Favorite Heart (Crimson Accent) --}}
                            <div class="absolute top-4 left-4 w-10 h-10 rounded-full glass-panel flex items-center justify-center text-slate-400 hover:text-zatara-red transition-colors z-10">
                                <span class="material-symbols-outlined text-[20px]" style="font-variation-settings:'FILL' 0">favorite</span>
                            </div>

                            {{-- Duration Tag --}}
                            <div class="absolute bottom-4 right-4 glass-panel px-4 py-1.5 rounded-xl shadow-sm z-10">
                                <span class="text-sm font-bold text-zatara-blue">{{ $instance->tripTemplate->duration_days ?? 5 }} أيام / {{ ($instance->tripTemplate->duration_days ?? 5) - 1 }} ليالٍ</span>
                            </div>

                            {{-- Seats Tag & Yield Pricing Badge --}}
                            @if($instance->price_override)
                                <div class="absolute top-4 right-4 bg-orange-100/90 backdrop-blur-md px-3 py-1 rounded-full text-xs font-bold text-orange-700 z-10 border border-orange-200 shadow-sm animate-pulse flex items-center gap-1">
                                    <span>🔥</span> آخر المقاعد - تم تحديث السعر
                                </div>
                            @elseif($instance->remaining_seats <= 10 && $instance->remaining_seats > 0)
                                <div class="absolute top-4 right-4 bg-rose-100/90 backdrop-blur-md px-3 py-1 rounded-full text-xs font-bold text-rose-700 z-10 border border-rose-200 shadow-sm animate-pulse">
                                    ⏳ أسرع! متبقي {{ $instance->remaining_seats }} مقاعد فقط
                                </div>
                            @elseif($instance->remaining_seats <= 0)
                                <div class="absolute top-4 right-4 bg-slate-100/90 backdrop-blur-md px-3 py-1 rounded-full text-xs font-bold text-slate-500 z-10 border border-slate-200 shadow-sm">
                                    مكتملة العدد
                                </div>
                            @endif
                        </div>
                        
                        {{-- Content --}}
                        <div class="px-3 pb-3">
                            <div class="flex justify-between items-start mb-2 gap-4">
                                <h3 class="text-2xl font-bold text-zatara-blue leading-tight">{{ $instance->tripTemplate->title }}</h3>
                                <div class="flex items-center gap-1 text-zatara-gold bg-zatara-gold/10 px-2 py-1 rounded-lg shrink-0">
                                    <span class="font-bold text-sm">4.8</span>
                                    <span class="material-symbols-outlined text-[16px]" style="font-variation-settings:'FILL' 1">star</span>
                                </div>
                            </div>
                            
                            <div class="text-slate-500 text-sm font-medium leading-relaxed line-clamp-2 mb-6 prose">
                                {!! $instance->tripTemplate->description ?? 'استمتع بتجربة فريدة تشمل الإقامة في أفخم المنتجعات والجولات السياحية المخصصة.' !!}
                            </div>
                            
                            {{-- Footer of Card --}}
                            <div class="flex items-center justify-between pt-4 border-t border-slate-100">
                                <div>
                                    <p class="text-xs text-slate-400 mb-0.5">تبدأ من</p>
                                    <div class="text-xl font-black text-zatara-blue">
                                        {{ number_format(($instance->tripPassengerCategories->min('price') ?? $instance->tripTemplate->base_price) + ($instance->price_override ? $instance->price_override_amount : 0)) }} <span class="text-sm font-medium">دولار</span>
                                    </div>
                                </div>
                                @if($instance->remaining_seats > 0)
                                    <button class="btn-secondary px-5 py-2.5 text-sm font-bold flex items-center gap-2 group-hover:bg-[#e09825]">
                                        احجز الآن
                                    </button>
                                @else
                                    <span class="px-5 py-2.5 text-sm font-bold text-slate-500 bg-slate-200 rounded-xl">
                                        مكتملة العدد
                                    </span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Pagination --}}
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32">
        {{ $tripInstances->links() }}
    </div>

</div>