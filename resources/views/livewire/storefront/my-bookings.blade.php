<div class="max-w-6xl mx-auto py-12 px-4 sm:px-6 lg:px-8" dir="rtl">
    <div class="flex items-center justify-between mb-10">
        <div>
            <h1 class="text-4xl font-extrabold text-gray-900 tracking-tight">حجوزاتي</h1>
            <p class="text-lg text-gray-500 mt-2">إدارة ومتابعة رحلاتك الفاخرة</p>
        </div>
        <div>
            <a href="{{ route('storefront.catalog', ['tenant' => $tenant->slug]) }}" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 transition">
                استكشاف رحلات جديدة
            </a>
        </div>
    </div>
    
    <div class="grid grid-cols-1 gap-8">
        @forelse($bookings as $booking)
            <div class="bg-white/80 backdrop-blur-xl rounded-3xl shadow-xl border border-gray-100 overflow-hidden hover:shadow-2xl transition duration-300 relative group">
                <!-- Status Badge Ribbon -->
                <div class="absolute top-6 left-6 z-10">
                    @php
                        $statusColors = [
                            'pending' => 'bg-amber-100 text-amber-800 border-amber-200',
                            'confirmed' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                            'waitlisted' => 'bg-purple-100 text-purple-800 border-purple-200',
                            'cancelled' => 'bg-rose-100 text-rose-800 border-rose-200',
                        ];
                        $badgeClass = $statusColors[$booking->booking_status->value] ?? 'bg-gray-100 text-gray-800 border-gray-200';
                    @endphp
                    <span class="px-4 py-1.5 text-sm font-bold rounded-full border {{ $badgeClass }} shadow-sm">
                        {{ $booking->booking_status->getLabel() ?? $booking->booking_status->value }}
                    </span>
                </div>

                <div class="flex flex-col md:flex-row">
                    <!-- Trip Image -->
                    <div class="md:w-1/3 relative h-64 md:h-auto overflow-hidden">
                        @if($booking->tripInstance->hasMedia('trip_images'))
                            <img src="{{ $booking->tripInstance->getFirstMediaUrl('trip_images') }}" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition duration-700" alt="Trip Image">
                        @elseif($booking->tripInstance->tripTemplate->hasMedia('images'))
                            <img src="{{ $booking->tripInstance->tripTemplate->getFirstMediaUrl('images') }}" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition duration-700" alt="Trip Image">
                        @else
                            <div class="absolute inset-0 w-full h-full bg-gradient-to-br from-indigo-100 to-purple-100 flex items-center justify-center">
                                <svg class="w-16 h-16 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                        @endif
                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                        <div class="absolute bottom-6 right-6 text-white">
                            <h2 class="text-2xl font-bold mb-1">{{ $booking->tripInstance->tripTemplate->title ?? 'رحلة مميزة' }}</h2>
                            <p class="text-indigo-100 flex items-center font-medium">
                                <svg class="w-4 h-4 ml-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                {{ $booking->tripInstance->start_date?->format('d M Y') }} - {{ $booking->tripInstance->end_date?->format('d M Y') }}
                            </p>
                        </div>
                    </div>

                    <!-- Details Section -->
                    <div class="md:w-2/3 p-8 flex flex-col justify-between">
                        <div>
                            <div class="flex flex-wrap justify-between items-start border-b border-gray-100 pb-6 mb-6">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-1">الرقم المرجعي (PNR)</h3>
                                    <p class="text-lg font-bold text-gray-900 tracking-widest">{{ $booking->pnr }}</p>
                                </div>
                                
                                <div class="text-left">
                                    <div class="inline-flex -space-x-2 -space-x-reverse overflow-hidden mb-2">
                                        @foreach($booking->passengers->take(5) as $passenger)
                                            <div class="inline-block h-10 w-10 rounded-full ring-2 ring-white bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 shadow-sm" title="{{ $passenger->first_name }}">
                                                {{ mb_substr($passenger->first_name, 0, 1) }}
                                            </div>
                                        @endforeach
                                        @if($booking->passengers->count() > 5)
                                            <div class="inline-block h-10 w-10 rounded-full ring-2 ring-white bg-indigo-50 flex items-center justify-center text-xs font-bold text-indigo-600 shadow-sm">
                                                +{{ $booking->passengers->count() - 5 }}
                                            </div>
                                        @endif
                                    </div>
                                    <p class="text-sm text-gray-500 font-medium">{{ $booking->passengers->count() }} مسافرين</p>
                                </div>
                            </div>

                            <!-- Ledger -->
                            <div class="bg-gray-50 rounded-2xl p-6 grid grid-cols-1 sm:grid-cols-3 gap-6 shadow-inner">
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">الإجمالي</p>
                                    <p class="text-xl font-bold text-gray-900">{{ number_format($booking->grand_total, 2) }} <span class="text-sm font-normal text-gray-500">SAR</span></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">المدفوع</p>
                                    <p class="text-xl font-bold text-indigo-600">{{ number_format($booking->total_paid, 2) }} <span class="text-sm font-normal text-indigo-400">SAR</span></p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">المتبقي</p>
                                    <p class="text-xl font-bold {{ $booking->balance_due > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                        {{ number_format($booking->balance_due, 2) }} <span class="text-sm font-normal {{ $booking->balance_due > 0 ? 'text-rose-400' : 'text-emerald-400' }}">SAR</span>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex flex-wrap justify-end gap-4 mt-8">
                            @if($booking->booking_status->value !== 'cancelled' && $booking->tripInstance->start_date > now())
                                @if($booking->cancellation_requested_at)
                                    <span class="inline-flex items-center px-5 py-2.5 border border-transparent text-sm font-medium rounded-xl text-amber-700 bg-amber-50">
                                        <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-amber-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                        جاري المعالجة
                                    </span>
                                @else
                                    <button wire:click="requestCancellation({{ $booking->id }})" wire:confirm="هل أنت متأكد من طلب إلغاء هذا الحجز؟ قد تطبق سياسة الإلغاء." class="inline-flex items-center px-5 py-2.5 border border-rose-200 text-sm font-medium rounded-xl text-rose-700 bg-white hover:bg-rose-50 hover:border-rose-300 transition shadow-sm">
                                        طلب إلغاء
                                    </button>
                                @endif
                            @endif

                            @if($booking->balance_due <= 0 && $booking->hasMedia('tickets'))
                                <a href="{{ route('storefront.ticket.download', ['tenant' => $tenant->slug, 'booking' => $booking->id]) }}" 
                                   class="inline-flex items-center px-6 py-2.5 border border-transparent text-sm font-bold rounded-xl text-white bg-gray-900 hover:bg-black shadow-lg shadow-gray-900/20 transition transform hover:-translate-y-0.5">
                                    <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                    تنزيل التذكرة
                                </a>
                            @else
                                <button disabled class="inline-flex items-center px-6 py-2.5 border border-gray-200 text-sm font-medium rounded-xl text-gray-400 bg-gray-50 cursor-not-allowed">
                                    <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                                    تذكرة مقفلة
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-span-full bg-white rounded-3xl shadow-sm border border-gray-100 p-16 text-center">
                <div class="mx-auto w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center mb-6">
                    <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"></path></svg>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">لا توجد حجوزات سابقة</h3>
                <p class="text-gray-500 mb-8 max-w-md mx-auto">لم تقم بأي حجوزات حتى الآن. ابدأ رحلتك الفاخرة القادمة معنا.</p>
                <a href="{{ route('storefront.catalog', ['tenant' => $tenant->slug]) }}" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-full shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 transition">
                    تصفح الرحلات
                </a>
            </div>
        @endforelse
    </div>
</div>
