<div class="py-12 bg-slate-50 min-h-screen font-tajawal" dir="rtl">
    <div class="max-w-4xl mx-auto px-4">
        <div class="animate-fade-up">
            <!-- The Ticket Header -->
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-zatara-gold/10 text-zatara-gold rounded-full flex items-center justify-center mx-auto mb-4 relative">
                    <span class="material-symbols-outlined text-[40px]">confirmation_number</span>
                    <div class="absolute -top-1 -right-1 w-5 h-5 bg-green-500 rounded-full border-2 border-white"></div>
                </div>
                <h2 class="text-3xl font-bold text-zatara-blue font-tajawal mb-2">تم تأكيد طلب الحجز المبدئي!</h2>
                <p class="text-slate-500 text-lg">طلبك قيد الانتظار حالياً. يرجى سداد المبلغ المطلوب أو استكمال الدفع لتأكيد الحجز النهائي.</p>
            </div>

            <!-- Digital Boarding Pass -->
            <div class="max-w-2xl mx-auto bg-white rounded-3xl shadow-xl overflow-hidden relative border border-slate-100">
                <!-- Perforated Edge Effect -->
                <div class="absolute left-0 top-1/2 -translate-y-1/2 -translate-x-1/2 w-8 h-8 bg-slate-50 rounded-full shadow-inner border-r border-slate-100"></div>
                <div class="absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/2 w-8 h-8 bg-slate-50 rounded-full shadow-inner border-l border-slate-100"></div>

                <!-- Ticket Content -->
                <div class="p-8 pb-10">
                    <div class="flex justify-between items-start mb-6 border-b border-slate-100 pb-6">
                        <div>
                            <p class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-1">رقم الحجز</p>
                            <p class="text-2xl font-bold text-zatara-blue tracking-widest font-mono">{{ $booking->pnr }}</p>
                        </div>
                        <div class="text-left">
                            <p class="text-xs text-slate-400 font-bold uppercase tracking-wider mb-1">المبلغ الإجمالي</p>
                            <p class="text-2xl font-bold text-zatara-gold">{{ number_format($booking->grand_total, 2) }} {{ $booking->currency ?? 'USD' }}</p>
                        </div>
                    </div>

                    <div class="bg-slate-50 rounded-2xl p-6 mb-6 border border-slate-100">
                        <h3 class="font-bold text-zatara-blue text-lg mb-4">{{ $booking->tripInstance ? optional($booking->tripInstance->tripTemplate)->title : $booking->snapshot_trip_title }}</h3>
                        
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-slate-400 mb-1">المغادرة</p>
                                <p class="font-bold text-slate-700 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">flight_takeoff</span> <span dir="ltr">{{ \Carbon\Carbon::parse($booking->tripInstance ? $booking->tripInstance->start_date : $booking->snapshot_start_date)->format('Y-m-d') }}</span></p>
                            </div>
                            <div>
                                <p class="text-slate-400 mb-1">العودة</p>
                                <p class="font-bold text-slate-700 flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">flight_land</span> <span dir="ltr">{{ \Carbon\Carbon::parse($booking->tripInstance ? $booking->tripInstance->end_date : $booking->snapshot_end_date)->format('Y-m-d') }}</span></p>
                            </div>
                            @if($booking->packageOption)
                                <div class="col-span-2 mt-2 pt-2 border-t border-slate-100">
                                    <p class="text-slate-400 mb-2">باقة الإقامة</p>
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-zatara-gold text-[20px]">hotel</span>
                                        <span class="font-bold text-slate-700">
                                            {{ $booking->packageOption->hotel_name ?? $booking->packageOption->name }}
                                        </span>
                                        @if($booking->packageOption->stars)
                                            <span class="text-zatara-gold text-xs">{{ str_repeat('★', $booking->packageOption->stars) }}</span>
                                        @endif
                                        <span class="text-xs text-slate-400 mr-2">
                                            ({{ $booking->packageOption->room_type }} - {{ $booking->packageOption->meal_plan }})
                                        </span>
                                    </div>
                                </div>
                            @endif
                            {{-- Hotel/Rooming redesign Ticket 2 — additive, alongside the
                                 packageOption block above, not replacing it. --}}
                            @if($booking->roomSelections->isNotEmpty())
                                <div class="col-span-2 mt-2 pt-2 border-t border-slate-100">
                                    <p class="text-slate-400 mb-2">الغرف المحجوزة</p>
                                    <div class="flex flex-col gap-1">
                                        @foreach($booking->roomSelections as $selection)
                                            <div class="flex items-center gap-2">
                                                <span class="material-symbols-outlined text-zatara-gold text-[20px]">bed</span>
                                                <span class="font-bold text-slate-700">
                                                    {{ $selection->roomType->name }} &times; {{ $selection->quantity }}
                                                </span>
                                                <span class="text-xs text-slate-400 mr-2">
                                                    ({{ $selection->occupancy_type->getLabel() }})
                                                </span>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <div class="col-span-2 mt-2 pt-2 border-t border-slate-100">
                                <p class="text-slate-400 mb-2">المسافرون <span dir="ltr">({{ $booking->passengers->count() }})</span></p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach($booking->passengers as $passenger)
                                        <span class="inline-block bg-white border border-slate-200 text-slate-600 px-3 py-1.5 rounded-lg text-xs font-medium shadow-sm">
                                            <span class="material-symbols-outlined text-[14px] align-middle mr-1 text-zatara-gold">person</span>
                                            {{ $passenger->first_name . ' ' . $passenger->last_name }} - <bdi>{{ optional($passenger->tripPassengerCategory)->name ?? '' }}</bdi>
                                        </span>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- QR and Expiry Section -->
                    <div class="border-t border-dashed border-slate-300 pt-8 flex flex-col items-center justify-center text-center relative">
                        <!-- Generated QR Code -->
                        <div class="bg-white p-3 rounded-2xl shadow-sm border border-slate-200 mb-4 transform hover:scale-105 transition-transform">
                            {!! $qrCodeSvg !!}
                        </div>
                        <p class="text-sm text-slate-500 mb-6 font-medium">يرجى إبراز هذا الرمز (QR) لموظف الفرع.</p>

                        @if($booking->expires_at)
                            <div class="bg-red-50 text-red-600 border border-red-100 px-6 py-4 rounded-2xl flex items-center gap-3 font-bold text-sm w-full max-w-sm justify-center shadow-sm">
                                <span class="material-symbols-outlined text-[20px] animate-pulse">timer</span>
                                <span>ينتهي الحجز في: <span dir="ltr">{{ \Carbon\Carbon::parse($booking->expires_at)->format('Y-m-d H:i') }}</span></span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="max-w-2xl mx-auto mt-8 flex flex-col sm:flex-row gap-4 justify-center">
                <button type="button" wire:click="downloadPdf" wire:loading.attr="disabled"
                        class="flex-1 bg-zatara-blue text-white px-8 py-4 rounded-2xl font-bold hover:bg-opacity-90 transition-all flex items-center justify-center gap-2 shadow-lg shadow-zatara-blue/20 relative">
                    <span wire:loading.remove wire:target="downloadPdf" class="flex items-center gap-2">
                        <span class="material-symbols-outlined animate-bounce">download</span>
                        {{ $booking->payment_status === \App\Enums\PaymentStatus::Unpaid ? 'تحميل إيصال مؤقت (PDF)' : 'تحميل التذكرة (PDF)' }}
                    </span>
                    <span wire:loading wire:target="downloadPdf" class="flex items-center gap-2">
                        <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        جاري التحميل...
                    </span>
                </button>
                <a href="https://wa.me/{{ $booking->tenant->settings['whatsapp'] ?? '1234567890' }}?text={{ urlencode('مرحباً زتارة، أود الدفع عبر التحويل البنكي لحجزي المبدئي رقم: ' . $booking->pnr) }}" target="_blank"
                    class="flex-1 bg-[#25D366] text-white px-8 py-4 rounded-2xl font-bold hover:bg-opacity-90 transition-all flex items-center justify-center gap-2 shadow-lg shadow-[#25D366]/20">
                    <span class="material-symbols-outlined">chat</span>
                    تواصل معنا (واتساب)
                </a>
            </div>
            
            <div class="text-center mt-12">
                <a href="{{ route('storefront.catalog', ['tenant' => $booking->tenant->slug]) }}" class="text-zatara-blue hover:text-zatara-gold font-bold transition-colors inline-flex items-center gap-2">
                    العودة للصفحة الرئيسية
                    <span class="material-symbols-outlined text-[18px]">arrow_forward</span>
                </a>
            </div>
        </div>
    </div>
</div>
