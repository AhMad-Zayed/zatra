<x-filament-panels::page>

{{-- ════════════════════════════════════════════════════════════════
     STEP 3: CONFIRMATION (PNR Screen)
     ════════════════════════════════════════════════════════════════ --}}
@if($step === 3)
<div class="min-h-[60vh] flex flex-col items-center justify-center text-center px-4">
    <div class="w-20 h-20 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center text-4xl mb-6 animate-bounce">
        ✅
    </div>
    <h2 class="text-2xl font-black text-gray-900 dark:text-white mb-2">الحجز تم بنجاح!</h2>
    <p class="text-gray-500 dark:text-gray-400 mb-2">
        {{ $customerName }} — {{ $selectedTrip['title'] ?? '' }}
    </p>
    <p class="text-gray-500 dark:text-gray-400 mb-8 text-sm">
        {{ $this->getTotalPassengers() }} مقعد محجوز
        @if($this->getTotalAmount() > 0)
        · الإجمالي: <strong>{{ number_format($this->getTotalAmount(), 0) }} $</strong>
        @endif
    </p>

    {{-- PNR Badge --}}
    <div class="inline-flex flex-col items-center gap-2 bg-white dark:bg-gray-900 border-2 border-green-400 rounded-3xl px-12 py-6 shadow-xl mb-8">
        <span class="text-xs font-semibold text-gray-400 uppercase tracking-widest">رقم الحجز (PNR)</span>
        <span class="text-5xl font-black tracking-widest text-green-600 dark:text-green-400 font-mono">{{ $pnr }}</span>
    </div>

    {{-- Note about incomplete data --}}
    <div class="flex items-start gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 text-right mb-8 max-w-md w-full">
        <span class="text-2xl">📱</span>
        <p class="text-amber-800 dark:text-amber-200 text-sm">
            المقاعد محجوزة. يمكن إرسال رابط للعميل لإكمال بيانات الركاب لاحقاً من صفحة الحجز.
        </p>
    </div>

    <div class="flex gap-3">
        <button wire:click="goToBooking"
            class="px-6 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-bold text-sm transition-colors">
            📄 فتح صفحة الحجز
        </button>
        <button wire:click="resetBooking"
            class="px-6 py-3 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 rounded-xl font-bold text-sm transition-colors">
            📞 حجز هاتفي جديد
        </button>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════════════════════
     STEP 2: SEAT ALLOCATION
     ════════════════════════════════════════════════════════════════ --}}
@if($step === 2)
<div class="space-y-4">

    {{-- Context bar --}}
    <div class="flex flex-wrap gap-3 items-center p-4 bg-gray-50 dark:bg-gray-800/60 rounded-xl border border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-2">
            <x-heroicon-o-user class="h-5 w-5 text-gray-400 flex-shrink-0" />
            <span class="font-semibold text-gray-800 dark:text-white">{{ $customerName }}</span>
            <span class="text-gray-400 text-sm">{{ $customerPhone }}</span>
        </div>
        <div class="w-px h-5 bg-gray-300 dark:bg-gray-600 hidden md:block"></div>
        <div class="flex items-center gap-2">
            <x-heroicon-o-map class="h-5 w-5 text-gray-400 flex-shrink-0" />
            <span class="font-semibold text-gray-800 dark:text-white">{{ $selectedTrip['title'] ?? '' }}</span>
            <span class="text-gray-400 text-sm" dir="ltr">{{ $selectedTrip['start'] ?? '' }} → {{ $selectedTrip['end'] ?? '' }}</span>
        </div>
        <div class="ml-auto">
            <span class="px-2 py-1 rounded-lg text-xs font-bold
                {{ ($selectedTrip['remaining'] ?? 0) <= 5 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
                {{ $selectedTrip['remaining'] ?? 0 }} مقعد متاح
            </span>
        </div>
    </div>

    {{-- Seat allocation table --}}
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
            <h2 class="font-bold text-gray-900 dark:text-white text-lg">توزيع المقاعد</h2>
            <p class="text-sm text-gray-500 mt-0.5">استخدم الأزرار لتحديد عدد المقاعد لكل فئة</p>
        </div>

        @if(empty($allocation))
            <div class="p-8 text-center text-gray-400">لا توجد فئات تسعير لهذه الرحلة</div>
        @else
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach($allocation as $index => $row)
            <div class="flex items-center gap-4 px-6 py-5">
                {{-- Category name --}}
                <div class="flex-1">
                    <div class="font-semibold text-gray-900 dark:text-white">{{ $row['category_name'] }}</div>
                    <div class="text-sm text-gray-500">{{ number_format($row['price'] / 100, 0) }} $ / شخص</div>
                </div>

                {{-- +/- Counter --}}
                <div class="flex items-center gap-3">
                    <button wire:click="decrement({{ $index }})"
                        class="w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 flex items-center justify-center text-gray-700 dark:text-gray-300 font-bold text-xl transition-colors {{ $row['count'] === 0 ? 'opacity-30 cursor-not-allowed' : '' }}"
                        @disabled($row['count'] === 0)>
                        −
                    </button>
                    <span class="w-10 text-center text-2xl font-black text-gray-900 dark:text-white tabular-nums">
                        {{ $row['count'] }}
                    </span>
                    <button wire:click="increment({{ $index }})"
                        class="w-10 h-10 rounded-xl bg-primary-500 hover:bg-primary-600 flex items-center justify-center text-white font-bold text-xl transition-colors shadow-sm">
                        +
                    </button>
                </div>

                {{-- Row subtotal --}}
                <div class="w-24 text-left">
                    @if($row['count'] > 0)
                    <div class="font-bold text-gray-900 dark:text-white">
                        {{ number_format(($row['price'] * $row['count']) / 100, 0) }} $
                    </div>
                    <div class="text-xs text-gray-400">{{ $row['count'] }} × {{ number_format($row['price'] / 100, 0) }}</div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>

        {{-- Totals footer --}}
        <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between">
            <div class="text-gray-600 dark:text-gray-400">
                <span class="font-medium">إجمالي الركاب:</span>
                <span class="text-xl font-black text-gray-900 dark:text-white mr-2">{{ $this->getTotalPassengers() }}</span>
            </div>
            <div class="text-left">
                <div class="text-xs text-gray-400">الإجمالي الكلي</div>
                <div class="text-2xl font-black text-primary-600 dark:text-primary-400">
                    {{ number_format($this->getTotalAmount(), 0) }} $
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Notes --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ملاحظات (اختياري)</label>
        <textarea wire:model.live="notes" rows="2" dir="rtl"
            placeholder="أي تفاصيل إضافية..."
            class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-3 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent resize-none">
        </textarea>
    </div>

    {{-- Actions --}}
    <div class="flex items-center justify-between pt-2">
        <button wire:click="backToStep1"
            class="px-5 py-3 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 rounded-xl font-medium text-sm transition-colors">
            ← رجوع
        </button>

        <button wire:click="submit"
            wire:loading.attr="disabled"
            class="px-10 py-3 bg-green-500 hover:bg-green-600 disabled:opacity-50 text-white rounded-xl font-black text-base transition-colors shadow-lg flex items-center gap-2">
            <span wire:loading.remove wire:target="submit">
                ✅ احجز {{ $this->getTotalPassengers() > 0 ? $this->getTotalPassengers() . ' مقاعد' : '' }} الآن
            </span>
            <span wire:loading wire:target="submit">⏳ جاري الحجز...</span>
        </button>
    </div>

</div>
@endif

{{-- ════════════════════════════════════════════════════════════════
     STEP 1: CUSTOMER + TRIP SELECTION
     ════════════════════════════════════════════════════════════════ --}}
@if($step === 1)

{{-- ── Waitlist Cart Modal (Slide-over) ── --}}
<x-filament::modal id="waitlist-cart" slide-over width="md">
    <x-slot name="heading">
        <div class="flex items-center gap-2 text-warning-600">
            <span>🕒</span> قائمة الانتظار (سلة الاختيارات)
        </div>
    </x-slot>
    
    <x-slot name="description">
        تم اختيار {{ count($waitlistTrips) }} رحلات. الرحلة الأولى هي الأولوية القصوى.
    </x-slot>

    @if(count($waitlistTrips) > 0)
    <div class="space-y-6 pb-4">
        {{-- Selected Trips List --}}
        <div class="space-y-3">
            <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">أولويات الرحلات المختارة</label>
            @php $tripInstances = \App\Models\TripInstance::with('tripTemplate')->whereIn('id', $waitlistTrips)->get()->keyBy('id'); @endphp
            @foreach($waitlistTrips as $index => $id)
                @php $trip = $tripInstances[$id] ?? null; @endphp
                @if($trip)
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-full bg-gray-200 dark:bg-gray-700 text-xs font-bold flex items-center justify-center text-gray-600 dark:text-gray-400">{{ $index + 1 }}</span>
                        <div>
                            <div class="font-bold text-sm text-gray-800 dark:text-gray-200">{{ $trip->tripTemplate?->title ?? 'رحلة' }}</div>
                            <div class="text-xs text-gray-500">{{ $trip->start_date?->format('Y-m-d') }}</div>
                        </div>
                    </div>
                    <button wire:click="removeFromWaitlistQueue({{ $id }})" class="text-red-500 hover:text-red-700 p-1">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
                @endif
            @endforeach
        </div>

        {{-- Form Fields --}}
        <div class="space-y-4 pt-4 border-t border-gray-100 dark:border-gray-800">
            <div>
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">عدد المقاعد المطلوبة</label>
                <input type="number" wire:model="waitlistSeats" min="1" class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-sm focus:ring-2 focus:ring-warning-400 focus:border-transparent transition-all">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-1">ملاحظات (نقطة التجمع / إلخ)</label>
                <textarea wire:model="waitlistNotes" rows="3" placeholder="مثال: يفضل المقاعد الأمامية، ينطلق من..." class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 px-4 py-2 text-sm focus:ring-2 focus:ring-warning-400 focus:border-transparent transition-all"></textarea>
            </div>
        </div>
    </div>
    @else
    <div class="py-12 text-center text-gray-500 dark:text-gray-400">
        السلة فارغة. الرجاء اختيار رحلات من القائمة.
    </div>
    @endif
    
    <x-slot name="footer">
        <button wire:click="submitWaitlist" @disabled(empty($waitlistTrips)) class="w-full py-3 bg-warning-500 hover:bg-warning-600 disabled:opacity-50 text-white rounded-xl font-bold text-sm transition-colors flex items-center justify-center gap-2 shadow-sm">
            ✅ اعتماد وحفظ في قائمة الانتظار
        </button>
    </x-slot>
</x-filament::modal>

<div class="grid grid-cols-1 md:grid-cols-2 gap-6">

    {{-- ── LEFT: Customer ── --}}
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 text-base font-bold">👤</div>
            <div>
                <h2 class="font-bold text-gray-900 dark:text-white">العميل</h2>
                <p class="text-xs text-gray-400">ابحث بالهاتف أو الاسم</p>
            </div>
        </div>
        <div class="p-5">

            @if($customer_id && !$showNewCustomer)
                {{-- Customer selected state --}}
                <div class="flex items-center gap-3 p-4 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700">
                    <div class="w-11 h-11 rounded-full bg-green-500 flex items-center justify-center text-white font-black text-lg flex-shrink-0">
                        {{ mb_substr($customerName, 0, 1) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-green-800 dark:text-green-200 truncate">{{ $customerName }}</div>
                        <div class="text-green-600 dark:text-green-400 text-sm font-mono">{{ $customerPhone }}</div>
                        
                        <div class="mt-2 text-xs text-green-700 dark:text-green-300">
                            <span class="font-bold inline-flex items-center gap-1 bg-green-200 dark:bg-green-800 px-2 py-0.5 rounded-full">
                                📊 إجمالي رحلاته: {{ $customerTotalBookings }}
                            </span>
                            @if($customerNotes)
                                <div class="mt-1.5 flex items-start gap-1 p-2 bg-green-100 dark:bg-green-900/50 rounded-lg border border-green-200 dark:border-green-800">
                                    <span>📝</span>
                                    <span class="italic text-gray-700 dark:text-gray-300">{{ $customerNotes }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                    <button wire:click="clearCustomer"
                        class="text-gray-400 hover:text-red-500 transition-colors flex-shrink-0 p-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- CRM Insights Panel --}}
                @if(count($customerBookings) > 0)
                <div class="mt-4 border-t border-gray-100 dark:border-gray-800 pt-4">
                    <h3 class="text-xs font-bold text-gray-500 uppercase tracking-widest mb-3 flex items-center gap-2">
                        <span>سجل حجوزات العميل النشطة</span>
                        <span class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 px-2 py-0.5 rounded-full">{{ count($customerBookings) }}</span>
                    </h3>
                    <div class="space-y-2 max-h-[300px] overflow-y-auto pr-1 custom-scrollbar">
                        @foreach($customerBookings as $b)
                        <div class="flex flex-col gap-2 p-3 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-100 dark:border-gray-700 text-sm">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="font-bold text-gray-800 dark:text-gray-200">{{ $b['trip_title'] }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">📅 {{ $b['start_date'] }} · 👤 {{ $b['passengers_count'] }} ركاب</div>
                                </div>
                                <div class="text-left">
                                    <span class="text-xs font-mono bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">{{ $b['pnr'] }}</span>
                                </div>
                            </div>
                            <div class="flex items-center justify-between border-t border-gray-200 dark:border-gray-700 pt-2 mt-1">
                                <div class="text-xs font-medium {{ $b['total_paid'] >= $b['grand_total'] ? 'text-green-600' : 'text-amber-600' }}">
                                    مدفوع: {{ number_format($b['total_paid'] / 100, 0) }}$ / {{ number_format($b['grand_total'] / 100, 0) }}$
                                </div>
                                <div class="flex gap-3">
                                    <a href="/admin/bookings/{{ $b['id'] }}" target="_blank" class="text-xs text-primary-600 hover:underline flex items-center gap-1" title="فتح الحجز في صفحة جديدة">👁️ عرض</a>
                                    <a href="/admin/bookings/{{ $b['id'] }}/edit" target="_blank" class="text-xs text-amber-600 hover:underline flex items-center gap-1" title="تعديل الحجز">✏️ تعديل</a>
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                @else
                <div class="mt-4 border-t border-gray-100 dark:border-gray-800 pt-4 text-center">
                    <span class="text-xs text-gray-400">لا توجد حجوزات نشطة سابقة لهذا العميل</span>
                </div>
                @endif
            @else
                {{-- Search input --}}
                <input
                    type="text"
                    wire:model.live.debounce.200ms="customerQuery"
                    placeholder="05xxxxxxxx أو اسم العميل..."
                    dir="rtl"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-3 text-sm focus:ring-2 focus:ring-primary-400 focus:border-transparent transition-all mb-3"
                    autofocus
                />

                {{-- Live search results --}}
                @php $results = $this->getCustomerResults(); @endphp
                @if(count($results) > 0)
                <div class="space-y-1 mb-3">
                    @foreach($results as $c)
                    <button wire:click="selectCustomer({{ $c['id'] }}, '{{ addslashes($c['name']) }}', '{{ $c['phone'] }}')"
                        class="w-full flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-primary-50 dark:hover:bg-primary-900/20 text-right transition-colors group">
                        <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-600 dark:text-gray-300 font-bold text-sm flex-shrink-0 group-hover:bg-primary-100 dark:group-hover:bg-primary-900/40 transition-colors">
                            {{ mb_substr($c['name'], 0, 1) }}
                        </div>
                        <div class="flex-1 min-w-0 text-right">
                            <div class="font-medium text-gray-900 dark:text-white text-sm truncate">{{ $c['name'] }}</div>
                            <div class="text-gray-400 text-xs font-mono">{{ $c['phone'] }}</div>
                        </div>
                    </button>
                    @endforeach
                </div>
                @elseif(mb_strlen($customerQuery) >= 2 && count($results) === 0)
                <div class="mb-3 text-center">
                    <p class="text-gray-400 text-sm mb-2">لا يوجد عميل بهذا الرقم</p>
                    <button wire:click="promptNewCustomer"
                        class="px-4 py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-medium transition-colors">
                        + إنشاء عميل جديد
                    </button>
                </div>
                @endif

                {{-- New customer form --}}
                @if($showNewCustomer)
                <div class="border border-primary-200 dark:border-primary-800 rounded-xl p-4 bg-primary-50 dark:bg-primary-900/20 space-y-3">
                    <p class="text-xs font-semibold text-primary-700 dark:text-primary-300">عميل جديد</p>
                    <input type="text" wire:model.live="newCustomerName" placeholder="الاسم الكامل *" dir="rtl"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 text-sm"/>
                    <input type="text" wire:model.live="customerPhone" placeholder="رقم الهاتف *" dir="ltr"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 text-sm"/>
                    <button wire:click="createAndSelectCustomer"
                        class="w-full py-2 bg-primary-500 hover:bg-primary-600 text-white rounded-lg text-sm font-bold transition-colors">
                        حفظ وتحديد
                    </button>
                </div>
                @endif
            @endif
        </div>
    </div>

    {{-- ── RIGHT: Trip ── --}}
    <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center text-amber-600 text-base">🗺️</div>
            <div>
                <h2 class="font-bold text-gray-900 dark:text-white">الرحلة</h2>
                <p class="text-xs text-gray-400">ابحث باسم الرحلة</p>
            </div>
        </div>
        <div class="p-5">

            @if($selectedTrip && !$tripQuery)
                {{-- Trip selected state --}}
                <div class="flex items-start gap-3 p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700">
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-amber-800 dark:text-amber-200">{{ $selectedTrip['title'] }}</div>
                        <div class="text-amber-600 dark:text-amber-400 text-sm mt-0.5" dir="ltr">
                            {{ $selectedTrip['start'] }} → {{ $selectedTrip['end'] }}
                        </div>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="text-xs px-2 py-0.5 rounded-full font-bold
                                {{ ($selectedTrip['remaining'] ?? 0) <= 5 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">
                                {{ $selectedTrip['remaining'] ?? 0 }} مقعد متاح
                            </span>
                            
                            {{-- Quick Waitlist Button --}}
                            @if(($selectedTrip['remaining'] ?? 0) <= 0 || true)
                            <button wire:click="queueForWaitlist({{ $selectedTrip['id'] }})" class="text-xs px-3 py-1 rounded-full font-bold bg-warning-100 text-warning-700 hover:bg-warning-200 transition-colors flex items-center gap-1 border border-warning-200">
                                🕒 إضافة للانتظار
                            </button>
                            @endif
                        </div>
                    </div>
                    <button wire:click="clearTrip"
                        class="text-gray-400 hover:text-red-500 transition-colors p-1 flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            @else
                <input
                    type="text"
                    wire:model.live.debounce.200ms="tripQuery"
                    placeholder="يافا، العقبة، إسطنبول..."
                    dir="rtl"
                    class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-3 text-sm focus:ring-2 focus:ring-amber-400 focus:border-transparent transition-all mb-3"
                />

                {{-- UI Filters --}}
                <div class="flex flex-wrap items-center gap-2 mb-4">
                    <button wire:click="toggleTripFilter('all')" class="px-3 py-1.5 rounded-full text-xs font-bold transition-colors border {{ empty($tripFilters) ? 'bg-amber-100 text-amber-700 border-amber-200' : 'bg-gray-50 text-gray-500 border-gray-200 hover:bg-gray-100' }}">
                        جميع الرحلات
                    </button>
                    <button wire:click="toggleTripFilter('active')" class="px-3 py-1.5 rounded-full text-xs font-bold transition-colors border {{ in_array('active', $tripFilters) ? 'bg-green-100 text-green-700 border-green-200' : 'bg-gray-50 text-gray-500 border-gray-200 hover:bg-gray-100' }}">
                        الرحلات النشطة
                    </button>
                    <button wire:click="toggleTripFilter('internal')" class="px-3 py-1.5 rounded-full text-xs font-bold transition-colors border {{ in_array('internal', $tripFilters) ? 'bg-blue-100 text-blue-700 border-blue-200' : 'bg-gray-50 text-gray-500 border-gray-200 hover:bg-gray-100' }}">
                        داخلي
                    </button>
                    <button wire:click="toggleTripFilter('external')" class="px-3 py-1.5 rounded-full text-xs font-bold transition-colors border {{ in_array('external', $tripFilters) ? 'bg-purple-100 text-purple-700 border-purple-200' : 'bg-gray-50 text-gray-500 border-gray-200 hover:bg-gray-100' }}">
                        خارجي
                    </button>
                </div>

                @php $trips = $this->getTripResults(); @endphp
                @if(count($trips) > 0)
                <div class="space-y-2">
                    @foreach($trips as $t)
                    @php $isFull = ($t['remaining'] ?? 0) <= 0; @endphp
                    <div class="w-full flex items-center justify-between gap-3 px-4 py-3 rounded-xl border text-right transition-all {{ $isFull ? 'border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/30' : 'border-gray-200 dark:border-gray-700 hover:border-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/20 cursor-pointer' }}"
                        @if(!$isFull) wire:click="selectTrip({{ $t['id'] }})" @endif>
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-gray-900 dark:text-white text-sm">{{ $t['title'] }}</div>
                            <div class="text-xs text-gray-400 mt-0.5" dir="ltr">{{ $t['start_date'] }} → {{ $t['end_date'] }}</div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-xs px-2 py-0.5 rounded-full font-bold
                                {{ $isFull ? 'bg-red-100 text-red-700' : (($t['remaining'] <= 5) ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700') }}">
                                @if($isFull) مكتملة @else {{ $t['remaining'] }} مقعد @endif
                            </span>
                            
                            @if($isFull)
                            <button wire:click.stop="queueForWaitlist({{ $t['id'] }})" title="إضافة لقائمة الانتظار" class="text-xs px-3 py-1 rounded-full font-bold bg-warning-100 text-warning-700 hover:bg-warning-200 transition-colors border border-warning-200 flex items-center gap-1">
                                🕒 انتظار
                            </button>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
                @elseif(mb_strlen($tripQuery) >= 1 && count($trips) === 0)
                <p class="text-center text-gray-400 text-sm py-4">لا توجد رحلات نشطة بهذا الاسم</p>
                @else
                <p class="text-center text-gray-300 dark:text-gray-600 text-sm py-6">ابدأ الكتابة للبحث عن رحلة...</p>
                @endif
            @endif

        </div>
    </div>

</div>

{{-- Proceed button --}}
<div class="flex justify-end mt-4">
    <button wire:click="goToStep2"
        class="px-10 py-3.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-black text-base transition-colors shadow-lg disabled:opacity-40 flex items-center gap-2"
        @disabled(!$customer_id || !$trip_instance_id)>
        تحديد المقاعد ←
    </button>
</div>

@if(!$customer_id || !$trip_instance_id)
<p class="text-center text-xs text-gray-400 mt-2">
    @if(!$customer_id && !$trip_instance_id) اختر العميل والرحلة للمتابعة
    @elseif(!$customer_id) اختر العميل للمتابعة
    @else اختر الرحلة للمتابعة
    @endif
</p>
@endif
@endif

</x-filament-panels::page>
