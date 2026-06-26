<div class="space-y-8">
    
    <!-- Hero / Title -->
    <div class="flex items-center justify-between pb-6 border-b border-slate-800">
        <div>
            <h1 class="text-3xl font-bold text-white tracking-tight">الرحلات القادمة</h1>
            <p class="text-slate-400 mt-2 text-sm">اكتشف أحدث رحلاتنا واحجز مقعدك الآن</p>
        </div>
    </div>

    <!-- Trips Grid -->
    @if($trips->isEmpty())
        <div class="text-center py-16 bg-slate-950 rounded-2xl border border-slate-800">
            <svg class="mx-auto h-12 w-12 text-slate-600 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
            <h3 class="text-lg font-medium text-slate-300">لا توجد رحلات متاحة حالياً</h3>
            <p class="mt-1 text-sm text-slate-500">يرجى العودة لاحقاً لاستكشاف وجهاتنا الجديدة.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($trips as $trip)
                <div class="bg-slate-950 rounded-2xl border border-slate-800 overflow-hidden shadow-lg transition-transform hover:-translate-y-1 hover:border-amber-500 group flex flex-col h-full">
                    
                    <!-- Trip Image Placeholder (Can integrate Spatie Media Library later) -->
                    <div class="h-48 bg-slate-800 relative">
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950 to-transparent"></div>
                        <div class="absolute bottom-4 start-4">
                            <span class="inline-flex items-center rounded-md bg-amber-500/10 px-2 py-1 text-xs font-medium text-amber-500 ring-1 ring-inset ring-amber-500/20">
                                {{ $trip->start_date->format('d M, Y') }}
                            </span>
                        </div>
                    </div>

                    <!-- Card Body -->
                    <div class="p-6 flex-grow flex flex-col">
                        <h2 class="text-xl font-bold text-white mb-2 line-clamp-2">
                            {{ $trip->tripTemplate->name ?? 'رحلة غير معروفة' }}
                        </h2>
                        
                        <p class="text-slate-400 text-sm mb-6 line-clamp-3">
                            {{ $trip->tripTemplate->description ?? 'استمتع بأفضل الأوقات معنا في هذه الرحلة المميزة.' }}
                        </p>

                        <div class="mt-auto flex items-center justify-between">
                            <div class="flex flex-col">
                                <span class="text-xs text-slate-500">تبدأ من</span>
                                <span class="text-lg font-bold text-amber-500">
                                    @php
                                        // Quick logic to find the lowest tier price
                                        $minPrice = $trip->tripPricingTiers->min('price');
                                    @endphp
                                    {{ $minPrice ? number_format($minPrice, 2) . ' ريال' : 'غير محدد' }}
                                </span>
                            </div>

                            <a href="{{ route('storefront.trip.details', ['tenant' => $currentTenant->slug, 'tripInstance' => $trip->id]) }}" 
                               class="inline-flex items-center justify-center rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm hover:bg-amber-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500 transition-colors">
                                تفاصيل الرحلة
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        <div class="mt-8">
            {{ $trips->links() }}
        </div>
    @endif

</div>
