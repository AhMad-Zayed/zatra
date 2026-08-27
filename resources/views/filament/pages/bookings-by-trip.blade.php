<x-filament-panels::page>
    {{-- Pure read-only browse screen -- no edit/cancel/transfer/payment actions anywhere here.
         BookingResource's own table keeps all of those untouched. --}}

    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    @php
        $trips = $this->getTrips();
    @endphp

    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dark:bg-gray-900 dark:border-gray-700">
        {{-- Level 1 column header --}}
        <div class="grid grid-cols-12 gap-4 p-4 bg-gray-100 dark:bg-white/5 border-b border-gray-200 dark:border-gray-700 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 items-center">
            <div class="col-span-3">الرحلة</div>
            <div class="col-span-2">تاريخ المغادرة</div>
            <div class="col-span-2 text-center">عدد الحاجزين</div>
            <div class="col-span-2 text-center">إجمالي الركاب</div>
            <div class="col-span-2 text-center">الوضع المالي</div>
            <div class="col-span-1"></div>
        </div>

        @if ($trips->isEmpty())
            <div class="p-10 text-center text-sm text-gray-500 dark:text-gray-400">
                لا توجد رحلات مطابقة لمعايير البحث الحالية.
            </div>
        @endif
        @foreach ($trips as $trip)
            @php
                $isTripOpen = $expandedTrips[$trip->id] ?? false;
                $bookings = $trip->bookings;
                $passengerCount = $bookings->sum(fn ($b) => $b->passengers->count());
            @endphp

            {{-- LEVEL 1: one row per TripInstance. Genuinely saturated background + a full-
                 intensity accent stripe -- the specific fix for the mockup's near-identical
                 level backgrounds. --}}
            <div class="relative border-b border-gray-200 dark:border-gray-700 last:border-0">
                <div class="absolute inset-y-0 right-0 w-1.5 bg-primary-600"></div>

                <button
                    type="button"
                    wire:click="toggleTrip({{ $trip->id }})"
                    class="w-full grid grid-cols-12 gap-4 p-4 items-center text-right bg-primary-200 hover:bg-primary-300 dark:bg-primary-600/40 dark:hover:bg-primary-600/50 transition-colors"
                >
                    <div class="col-span-3 flex items-center gap-2 font-bold text-gray-900 dark:text-white">
                        <x-heroicon-o-map class="h-5 w-5 text-primary-700 dark:text-primary-300" />
                        {{ $trip->tripTemplate?->title ?? '—' }}
                    </div>
                    <div class="col-span-2 text-sm text-gray-700 dark:text-gray-300">
                        {{ $trip->start_date?->format('M j, Y') ?? '—' }}
                    </div>
                    <div class="col-span-2 text-center font-semibold text-gray-900 dark:text-white">
                        {{ $bookings->count() }}
                    </div>
                    <div class="col-span-2 text-center font-semibold text-gray-900 dark:text-white">
                        {{ $passengerCount }}
                    </div>
                    <div class="col-span-2 flex justify-center">
                        <x-filament::badge :color="$this->tripFinancialColor($bookings)">
                            {{ $this->tripFinancialLabel($bookings) }}
                        </x-filament::badge>
                    </div>
                    <div class="col-span-1 flex justify-end text-gray-500 dark:text-gray-400">
                        @if ($isTripOpen)
                            <x-heroicon-o-chevron-up class="h-5 w-5" />
                        @else
                            <x-heroicon-o-chevron-down class="h-5 w-5" />
                        @endif
                    </div>
                </button>

                @if ($isTripOpen)
                    <div class="flex flex-col">
                        @if ($bookings->isEmpty())
                            <div class="p-6 text-center text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-white/5">
                                لا توجد حجوزات فعّالة لهذه الرحلة.
                            </div>
                        @else
                            {{-- Level 2 column header --}}
                            <div class="grid grid-cols-12 gap-4 p-3 pr-10 bg-gray-50 dark:bg-white/5 border-b border-t border-gray-200 dark:border-gray-700 text-xs font-bold uppercase tracking-wide text-gray-500 dark:text-gray-400 items-center">
                                <div class="col-span-3">العميل (مسؤول الحجز)</div>
                                <div class="col-span-2">الهاتف</div>
                                <div class="col-span-2 text-center">عدد الركاب</div>
                                <div class="col-span-2 text-center">حالة الدفع</div>
                                <div class="col-span-2 text-center">حالة الوثائق</div>
                                <div class="col-span-1"></div>
                            </div>

                            @foreach ($bookings as $booking)
                                @php($isBookingOpen = $expandedBookings[$booking->id] ?? false)

                                {{-- LEVEL 2: one row per Booking. A real, visible contrast band
                                     from both Level 1 (saturated blue) and Level 3 (plain
                                     white) -- a neutral gray tint, not another shade of blue. --}}
                                <div class="border-b border-dashed border-gray-200 dark:border-gray-700 last:border-0 pr-6">
                                    <button
                                        type="button"
                                        wire:click="toggleBooking({{ $booking->id }})"
                                        class="w-full grid grid-cols-12 gap-4 p-3 items-center text-right bg-gray-100 hover:bg-gray-200 dark:bg-white/5 dark:hover:bg-white/10 transition-colors"
                                    >
                                        <div class="col-span-3 font-semibold text-gray-900 dark:text-white">
                                            {{ $booking->customer?->name ?? '—' }}
                                        </div>
                                        <div class="col-span-2 text-sm text-gray-600 dark:text-gray-400" dir="ltr">
                                            {{ $booking->customer?->phone ?? '—' }}
                                        </div>
                                        <div class="col-span-2 text-center text-sm text-gray-700 dark:text-gray-300">
                                            {{ $booking->passengers->count() }}
                                        </div>
                                        <div class="col-span-2 flex justify-center">
                                            <x-filament::badge :color="$booking->payment_status?->getColor()">
                                                {{ $booking->payment_status?->getLabel() ?? '—' }}
                                            </x-filament::badge>
                                        </div>
                                        <div class="col-span-2 text-center text-sm text-gray-700 dark:text-gray-300">
                                            {{ $this->documentsCompletionFraction($booking) }}
                                        </div>
                                        <div class="col-span-1 flex justify-end text-gray-500 dark:text-gray-400">
                                            @if ($isBookingOpen)
                                                <x-heroicon-o-chevron-up class="h-4 w-4" />
                                            @else
                                                <x-heroicon-o-chevron-down class="h-4 w-4" />
                                            @endif
                                        </div>
                                    </button>

                                    @if ($isBookingOpen)
                                        <div class="flex flex-col bg-white dark:bg-gray-900">
                                            @foreach ($booking->passengers as $passenger)
                                                {{-- LEVEL 3: one row per Passenger. Plain white --
                                                     the cleanest, lightest band, completing the
                                                     3-step contrast ladder. --}}
                                                <div class="grid grid-cols-12 gap-4 px-4 py-2 pr-14 items-center border-b border-dashed border-gray-100 dark:border-gray-800 last:border-0 hover:bg-gray-50 dark:hover:bg-white/5">
                                                    <div class="col-span-5 flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200">
                                                        <x-heroicon-o-user class="h-4 w-4 text-gray-400" />
                                                        {{ $passenger->display_name }}
                                                        @if ($this->isBookingOwner($passenger, $booking))
                                                            <span class="bg-primary-100 text-primary-800 dark:bg-primary-900 dark:text-primary-200 text-[10px] font-semibold px-2 py-0.5 rounded">
                                                                المسؤول عن الحجز
                                                            </span>
                                                        @endif
                                                    </div>
                                                    <div class="col-span-4 flex items-center gap-1.5 text-sm">
                                                        @if ($passenger->requirements_complete)
                                                            <x-heroicon-o-check-circle class="h-4 w-4 text-success-600" />
                                                            <span class="text-gray-600 dark:text-gray-400">مستوفاة</span>
                                                        @else
                                                            <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-warning-600" />
                                                            <span class="text-gray-600 dark:text-gray-400">ناقصة</span>
                                                        @endif
                                                    </div>
                                                    <div class="col-span-3 text-center text-sm text-gray-600 dark:text-gray-400">
                                                        {{ $this->seatOrRoomDisplay($passenger) }}
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
