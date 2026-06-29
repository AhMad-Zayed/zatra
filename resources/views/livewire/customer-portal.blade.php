<div dir="rtl" class="min-h-screen bg-slate-900 font-sans text-slate-200">
    <!-- Header -->
    <header class="bg-slate-950 border-b border-amber-500/20 sticky top-0 z-50 shadow-lg shadow-black/50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-extrabold text-amber-500 tracking-tight">
                    {{ $tenant->name }}
                </h1>
                <p class="text-slate-400 text-sm mt-1">بوابة العملاء</p>
            </div>
            <nav class="hidden md:flex space-x-8 space-x-reverse">
                <a href="{{ route('storefront.catalog', ['tenant_slug' => $tenant->slug]) }}" class="text-slate-300 hover:text-amber-400 transition-colors font-medium">الرئيسية</a>
                <a href="#" class="text-amber-400 border-b-2 border-amber-500 pb-1 font-bold">حجوزاتي</a>
            </nav>
            <div class="flex items-center gap-4">
                <button wire:click="/* handle logout action */" class="text-slate-400 hover:text-red-400 transition-colors text-sm font-medium flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    تسجيل الخروج
                </button>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <!-- Greeting -->
        <div class="mb-12">
            <h2 class="text-4xl font-black text-slate-100 mb-2">
                مرحباً بعودتك، <span class="text-amber-500">{{ auth('customer')->user()->name }}</span>
            </h2>
            <p class="text-slate-400 text-lg">إليك تفاصيل حجوزاتك وحالتها الحالية.</p>
        </div>

        <!-- Bookings Grid -->
        @if($bookings->isEmpty())
            <div class="bg-slate-800/40 border border-slate-700/50 rounded-3xl p-12 text-center max-w-2xl mx-auto">
                <div class="w-24 h-24 bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner">
                    <svg class="w-12 h-12 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
                <h3 class="text-2xl font-bold text-slate-200 mb-2">لا توجد حجوزات حتى الآن</h3>
                <p class="text-slate-400 mb-8">لم تقم بإجراء أي حجوزات معنا بعد. تصفح رحلاتنا المميزة واحجز مغامرتك القادمة.</p>
                <a href="{{ route('storefront.catalog', ['tenant_slug' => $tenant->slug]) }}" class="inline-block bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold py-3 px-8 rounded-xl transition-all shadow-[0_0_20px_rgba(245,158,11,0.2)]">
                    تصفح الرحلات المتاحة
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                @foreach($bookings as $booking)
                    <div class="bg-slate-800/60 border border-slate-700/50 rounded-2xl p-6 shadow-xl relative overflow-hidden flex flex-col transition-all hover:border-slate-600">
                        <!-- Decorative Top Border -->
                        <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-amber-600 to-amber-300"></div>

                        <div class="flex justify-between items-start mb-6">
                            <div>
                                <span class="text-xs font-mono text-slate-500 mb-1 block">رقم المرجع: #{{ str_pad($booking->id, 6, '0', STR_PAD_LEFT) }}</span>
                                <h3 class="text-xl font-bold text-slate-100">{{ $booking->tripInstance->template->title }}</h3>
                            </div>
                            
                            <div class="flex flex-col gap-2 items-end">
                                <!-- Booking Status Badge -->
                                @if($booking->booking_status === \App\Enums\BookingStatus::Confirmed)
                                    <span class="bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 px-3 py-1 rounded-full text-xs font-bold shadow-[0_0_10px_rgba(16,185,129,0.1)]">مؤكد</span>
                                @elseif($booking->booking_status === \App\Enums\BookingStatus::Pending)
                                    <span class="bg-amber-500/10 text-amber-500 border border-amber-500/20 px-3 py-1 rounded-full text-xs font-bold">قيد الانتظار</span>
                                @else
                                    <span class="bg-slate-700 text-slate-300 px-3 py-1 rounded-full text-xs font-bold">ملغى</span>
                                @endif

                                <!-- Payment Status Badge -->
                                @if($booking->payment_status === \App\Enums\PaymentStatus::Paid)
                                    <span class="text-emerald-400 text-xs font-bold flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> مدفوع
                                    </span>
                                @elseif($booking->payment_status === \App\Enums\PaymentStatus::Partial)
                                    <span class="text-amber-500 text-xs font-bold flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg> دفع جزئي
                                    </span>
                                @else
                                    <span class="text-red-400 text-xs font-bold flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg> غير مدفوع
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-6 text-sm">
                            <div class="bg-slate-900/50 p-3 rounded-xl border border-slate-700/50">
                                <span class="block text-slate-500 text-xs mb-1">تاريخ المغادرة</span>
                                <span class="font-medium text-slate-200">{{ $booking->tripInstance->start_date->translatedFormat('d M Y') }}</span>
                            </div>
                            <div class="bg-slate-900/50 p-3 rounded-xl border border-slate-700/50">
                                <span class="block text-slate-500 text-xs mb-1">تاريخ العودة</span>
                                <span class="font-medium text-slate-200">{{ $booking->tripInstance->end_date->translatedFormat('d M Y') }}</span>
                            </div>
                        </div>

                        <div class="flex justify-between items-center mb-6 pt-4 border-t border-slate-700/50">
                            <div>
                                <span class="block text-slate-400 text-xs">الإجمالي</span>
                                <span class="font-bold text-lg text-slate-100">{{ number_format($booking->grand_total) }} ريال</span>
                            </div>
                            <div class="text-left">
                                <span class="block text-slate-400 text-xs">المبلغ المتبقي</span>
                                <span class="font-black text-xl text-amber-500">{{ number_format($booking->balance_due) }} ريال</span>
                            </div>
                        </div>

                        <!-- CTA Actions (Stick to bottom) -->
                        <div class="mt-auto pt-2">
                            @if($booking->balance_due > 0)
                                <a href="#" class="block w-full text-center bg-amber-500 hover:bg-amber-400 text-slate-900 font-bold py-3 px-6 rounded-xl transition-all shadow-[0_0_15px_rgba(245,158,11,0.2)] flex items-center justify-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>
                                    إكمال الدفع
                                </a>
                            @elseif($booking->payment_status === \App\Enums\PaymentStatus::Paid && $booking->booking_status === \App\Enums\BookingStatus::Confirmed)
                                <a href="{{ route('storefront.ticket.download', ['tenant_slug' => $tenant->slug, 'booking' => $booking->id]) }}" class="block w-full text-center bg-slate-700 hover:bg-slate-600 text-white font-bold py-3 px-6 rounded-xl transition-all flex items-center justify-center gap-2 border border-slate-600 hover:border-slate-500">
                                    <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                    تحميل التذكرة (E-Ticket)
                                </a>
                            @else
                                <div class="bg-slate-800 text-center py-3 rounded-xl border border-slate-700 text-slate-400 text-sm font-medium">
                                    جاري المعالجة
                                </div>
                            @endif
                        </div>

                    </div>
                @endforeach
            </div>
        @endif
    </main>
</div>
