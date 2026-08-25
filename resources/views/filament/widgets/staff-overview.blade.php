<x-filament-widgets::widget>
    @php
        $stats = $this->getStats();
        $bookingsToday = $stats['bookingsToday'];
        $upcomingTrips = $stats['upcomingTrips'];
        $cancellationRequests = $stats['cancellationRequests'];
        $pendingPayments = $stats['pendingPayments'];
    @endphp

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        <div class="fi-section rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
            <div class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $bookingsToday }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">حجوزات اليوم</div>
        </div>

        <div class="fi-section rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
            <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $upcomingTrips->count() }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">رحلات هذا الشهر</div>
        </div>

        @if ($cancellationRequests > 0)
            <a href="{{ \App\Filament\Resources\BookingResource::getUrl('index') }}"
                class="rounded-lg border border-danger-200 bg-danger-50 p-5 transition-colors hover:bg-danger-100 dark:border-danger-800 dark:bg-danger-900/20">
                <div class="text-3xl font-bold text-danger-600 dark:text-danger-400">{{ $cancellationRequests }}</div>
                <div class="mt-1 text-sm font-medium text-danger-600 dark:text-danger-400">طلبات إلغاء بانتظار المراجعة</div>
            </a>
        @else
            <div class="fi-section rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
                <div class="text-3xl font-bold text-gray-900 dark:text-white">{{ $cancellationRequests }}</div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">طلبات إلغاء</div>
            </div>
        @endif

        <div class="fi-section rounded-lg border border-gray-200 bg-white p-5 dark:border-white/10 dark:bg-gray-900">
            <div class="text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $pendingPayments }}</div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">حجوزات بانتظار الدفع</div>
        </div>
    </div>

    <div class="mt-6">
        <h3 class="mb-3 text-sm font-semibold text-gray-500 dark:text-gray-400">الرحلات القادمة (٣٠ يوم)</h3>

        @if ($upcomingTrips->isEmpty())
            <div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-gray-300 py-10 text-center dark:border-gray-700">
                <x-heroicon-o-calendar class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">لا توجد رحلات قادمة مجدولة خلال الشهر القادم</p>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($upcomingTrips as $trip)
                    @php
                        $remaining = $trip->remaining_seats ?? $trip->available_seats;
                        $total = $trip->available_seats ?? 1;
                        $pct = $total > 0 ? round((($total - $remaining) / $total) * 100) : 0;
                    @endphp
                    <div class="flex items-center gap-4 rounded-lg border border-gray-200 bg-white p-4 transition-shadow hover:shadow-[0_4px_12px_rgba(15,76,129,0.08)] dark:border-white/10 dark:bg-gray-900">
                        <div class="min-w-[56px] flex-shrink-0 rounded-md bg-primary-50 px-3 py-2 text-center dark:bg-primary-900/30">
                            <div class="text-xs font-medium text-primary-600 dark:text-primary-400">{{ \Carbon\Carbon::parse($trip->start_date)->translatedFormat('M') }}</div>
                            <div class="text-xl font-bold text-primary-700 dark:text-primary-300">{{ \Carbon\Carbon::parse($trip->start_date)->format('d') }}</div>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="truncate font-semibold text-gray-900 dark:text-white">
                                {{ $trip->tripTemplate?->title ?? 'رحلة' }}
                            </div>
                            <div class="mt-2 flex items-center gap-2">
                                <div class="h-1.5 flex-1 rounded-full bg-gray-200 dark:bg-gray-700">
                                    <div class="h-1.5 rounded-full {{ $pct > 80 ? 'bg-danger-500' : ($pct > 50 ? 'bg-warning-500' : 'bg-success-500') }}"
                                        style="width: {{ $pct }}%"></div>
                                </div>
                                <span class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">{{ $remaining }} مقعد متاح</span>
                            </div>
                        </div>

                        <a href="{{ \App\Filament\Resources\TripInstanceResource::getUrl('edit', ['record' => $trip->id]) }}"
                            class="flex-shrink-0 rounded-md border border-gray-200 px-3 py-1.5 text-xs text-gray-600 transition-colors hover:bg-gray-50 dark:border-white/10 dark:text-gray-400 dark:hover:bg-white/5">
                            تفاصيل
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
