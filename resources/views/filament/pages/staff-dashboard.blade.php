<x-filament-panels::page>
    @php
        $stats = $this->getStats();
        $bookingsToday       = $stats['bookingsToday'];
        $upcomingTrips       = $stats['upcomingTrips'];
        $cancellationRequests = $stats['cancellationRequests'];
        $pendingPayments     = $stats['pendingPayments'];
    @endphp

    {{-- ──────────────────── Quick Actions ──────────────────── --}}
    <div class="mb-8">
        <h2 class="text-base font-semibold text-gray-500 dark:text-gray-400 mb-4 uppercase tracking-wide">إجراءات سريعة</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">

            {{-- New Booking --}}
            <a href="{{ \App\Filament\Pages\QuickBookingPage::getUrl() }}"
                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl bg-gradient-to-br from-primary-500 to-primary-600 text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200 cursor-pointer">
                <div class="text-4xl">🎫</div>
                <div class="text-center">
                    <div class="font-bold text-lg leading-tight">حجز جديد</div>
                    <div class="text-primary-100 text-xs mt-1">5 خطوات سريعة</div>
                </div>
            </a>

            {{-- New Trip --}}
            <a href="{{ \App\Filament\Resources\TripInstanceResource::getUrl('index') }}"
                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl bg-gradient-to-br from-amber-500 to-amber-600 text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200 cursor-pointer">
                <div class="text-4xl">🗺️</div>
                <div class="text-center">
                    <div class="font-bold text-lg leading-tight">رحلة جديدة</div>
                    <div class="text-amber-100 text-xs mt-1">من قالب مباشرةً</div>
                </div>
            </a>

            {{-- Search Bookings --}}
            <a href="{{ \App\Filament\Resources\BookingResource::getUrl('index') }}"
                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200 cursor-pointer">
                <div class="text-4xl">🔍</div>
                <div class="text-center">
                    <div class="font-bold text-lg leading-tight">بحث الحجوزات</div>
                    <div class="text-blue-100 text-xs mt-1">بحث بالرقم أو الاسم</div>
                </div>
            </a>

            {{-- Customers --}}
            <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('index') }}"
                class="group flex flex-col items-center justify-center gap-3 p-6 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 text-white shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-200 cursor-pointer">
                <div class="text-4xl">👥</div>
                <div class="text-center">
                    <div class="font-bold text-lg leading-tight">العملاء</div>
                    <div class="text-emerald-100 text-xs mt-1">إدارة بيانات العملاء</div>
                </div>
            </a>

        </div>
    </div>

    {{-- ──────────────────── Stats Row ──────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">

        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
            <div class="text-3xl font-black text-primary-600 dark:text-primary-400">{{ $bookingsToday }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">حجوزات اليوم</div>
        </div>

        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
            <div class="text-3xl font-black text-amber-500">{{ $upcomingTrips->count() }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">رحلات هذا الشهر</div>
        </div>

        @if($cancellationRequests > 0)
        <a href="{{ \App\Filament\Resources\BookingResource::getUrl('index') }}"
            class="rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-5 shadow-sm hover:bg-red-100 transition-colors">
            <div class="text-3xl font-black text-red-600">{{ $cancellationRequests }}</div>
            <div class="text-sm text-red-500 mt-1 font-medium">⚠️ طلبات إلغاء</div>
        </a>
        @else
        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
            <div class="text-3xl font-black text-green-500">{{ $cancellationRequests }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">طلبات إلغاء</div>
        </div>
        @endif

        <div class="rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 p-5 shadow-sm">
            <div class="text-3xl font-black text-orange-500">{{ $pendingPayments }}</div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">حجوزات بانتظار الدفع</div>
        </div>

    </div>

    {{-- ──────────────────── Upcoming Trips ──────────────────── --}}
    <div>
        <h2 class="text-base font-semibold text-gray-500 dark:text-gray-400 mb-4 uppercase tracking-wide">الرحلات القادمة (30 يوم)</h2>

        @if($upcomingTrips->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 dark:border-gray-700 p-10 text-center text-gray-400">
                <div class="text-4xl mb-2">🗓️</div>
                <div>لا توجد رحلات قادمة مجدولة</div>
                <a href="{{ \App\Filament\Resources\TripInstanceResource::getUrl('index') }}"
                    class="inline-block mt-4 px-4 py-2 bg-primary-500 text-white rounded-lg text-sm hover:bg-primary-600 transition-colors">
                    إضافة رحلة الآن
                </a>
            </div>
        @else
            <div class="space-y-3">
                @foreach($upcomingTrips as $trip)
                    @php
                        $remaining = $trip->remaining_seats ?? $trip->available_seats;
                        $total     = $trip->available_seats ?? 1;
                        $pct       = $total > 0 ? round((($total - $remaining) / $total) * 100) : 0;
                    @endphp
                    <div class="flex items-center gap-4 p-4 rounded-xl bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md transition-shadow">
                        {{-- Date badge --}}
                        <div class="flex-shrink-0 text-center bg-primary-50 dark:bg-primary-900/30 rounded-xl px-3 py-2 min-w-[60px]">
                            <div class="text-xs text-primary-500 font-medium">{{ \Carbon\Carbon::parse($trip->start_date)->translatedFormat('M') }}</div>
                            <div class="text-2xl font-black text-primary-700 dark:text-primary-300">{{ \Carbon\Carbon::parse($trip->start_date)->format('d') }}</div>
                        </div>

                        {{-- Trip info --}}
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-gray-900 dark:text-white truncate">
                                {{ $trip->tripTemplate?->title ?? 'رحلة' }}
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5">
                                حتى {{ \Carbon\Carbon::parse($trip->end_date)->format('d M') }}
                            </div>
                            {{-- Occupancy bar --}}
                            <div class="mt-2 flex items-center gap-2">
                                <div class="flex-1 bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                    <div class="h-1.5 rounded-full transition-all duration-500 {{ $pct > 80 ? 'bg-red-500' : ($pct > 50 ? 'bg-amber-500' : 'bg-green-500') }}"
                                        style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="text-xs text-gray-500 whitespace-nowrap">{{ $remaining }} مقعد متاح</span>
                            </div>
                        </div>

                        {{-- Action button --}}
                        <a href="{{ \App\Filament\Resources\TripInstanceResource::getUrl('edit', ['record' => $trip->id]) }}"
                            class="flex-shrink-0 px-3 py-1.5 text-xs border border-gray-200 dark:border-gray-700 rounded-lg text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                            تفاصيل
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</x-filament-panels::page>
