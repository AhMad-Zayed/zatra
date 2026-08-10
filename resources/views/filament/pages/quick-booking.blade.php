<x-filament-panels::page>
    {{-- ──────────────────── Progress Bar ──────────────────── --}}
    <div class="mb-6">
        <div class="flex items-center justify-between mb-2">
            @php
                $steps = [
                    1 => ['icon' => '👤', 'label' => 'العميل'],
                    2 => ['icon' => '🗺️', 'label' => 'الرحلة'],
                    3 => ['icon' => '👥', 'label' => 'الركاب'],
                    4 => ['icon' => '💳', 'label' => 'الدفع'],
                    5 => ['icon' => '✅', 'label' => 'التأكيد'],
                ];
            @endphp
            @foreach($steps as $num => $step)
                <div class="flex flex-col items-center flex-1">
                    <div @class([
                        'w-10 h-10 rounded-full flex items-center justify-center text-lg font-bold transition-all duration-300',
                        'bg-primary-500 text-white shadow-lg scale-110' => $currentStep === $num,
                        'bg-green-500 text-white' => $currentStep > $num,
                        'bg-gray-200 dark:bg-gray-700 text-gray-500' => $currentStep < $num,
                    ])>
                        @if($currentStep > $num) ✓ @else {{ $step['icon'] }} @endif
                    </div>
                    <span @class([
                        'text-xs mt-1 font-medium',
                        'text-primary-600 dark:text-primary-400' => $currentStep === $num,
                        'text-green-600' => $currentStep > $num,
                        'text-gray-400' => $currentStep < $num,
                    ])>{{ $step['label'] }}</span>
                </div>
                @if(!$loop->last)
                    <div @class([
                        'flex-1 h-1 mx-1 rounded transition-all duration-300',
                        'bg-green-400' => $currentStep > $num,
                        'bg-gray-200 dark:bg-gray-700' => $currentStep <= $num,
                    ])></div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- ──────────────────── Step 1: العميل ──────────────────── --}}
    @if($currentStep === 1)
    <div class="fi-section rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">👤 الخطوة 1: اختيار العميل</h2>
        <p class="text-sm text-gray-500 mb-6">ابحث برقم الهاتف أو الاسم. إذا لم يكن موجوداً، أنشئه مباشرةً.</p>

        @if($customer_id && !$creatingCustomer)
            {{-- Customer Found --}}
            <div class="flex items-center gap-4 p-4 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
                <div class="w-12 h-12 rounded-full bg-green-500 flex items-center justify-center text-white text-xl font-bold">
                    {{ mb_substr($customer_name, 0, 1) }}
                </div>
                <div class="flex-1">
                    <div class="font-bold text-green-800 dark:text-green-200 text-lg">{{ $customer_name }}</div>
                    <div class="text-green-600 dark:text-green-400 text-sm">{{ $customer_phone }}</div>
                </div>
                <button wire:click="clearCustomer" class="text-gray-400 hover:text-red-500 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        @else
            {{-- Search Box --}}
            <div class="flex gap-3 mb-4">
                <input
                    type="text"
                    wire:model.live="customerSearch"
                    placeholder="ابحث بالرقم أو الاسم... (3 أحرف كحد أدنى)"
                    class="flex-1 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-3 text-sm focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                    dir="rtl"
                />
                <button
                    wire:click="searchCustomer"
                    class="px-5 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm transition-colors whitespace-nowrap"
                >
                    🔍 بحث
                </button>
            </div>

            @if($creatingCustomer)
                <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 mb-4">
                    <p class="text-amber-700 dark:text-amber-300 text-sm font-medium mb-3">⚠️ لم يتم العثور على عميل. أدخل البيانات لإنشاء عميل جديد:</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">الاسم الكامل *</label>
                            <input type="text" wire:model.live="customer_name" placeholder="اسم العميل"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 text-sm" dir="rtl"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">رقم الهاتف *</label>
                            <input type="text" wire:model.live="customer_phone" placeholder="05xxxxxxxx"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-3 py-2 text-sm" dir="ltr"/>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
    @endif

    {{-- ──────────────────── Step 2: الرحلة ──────────────────── --}}
    @if($currentStep === 2)
    <div class="fi-section rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">🗺️ الخطوة 2: اختيار الرحلة</h2>
        <p class="text-sm text-gray-500 mb-6">انقر على الرحلة المناسبة لاختيارها.</p>

        @php $trips = $this->getAvailableTrips(); @endphp

        @if($trips->isEmpty())
            <div class="text-center py-12 text-gray-400">
                <div class="text-4xl mb-2">🗓️</div>
                <div>لا توجد رحلات نشطة قادمة حالياً</div>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($trips as $trip)
                    @php
                        $remaining = $trip->remaining_seats ?? $trip->available_seats;
                        $isSelected = $trip_instance_id === $trip->id;
                        $isFull = $remaining <= 0;
                    @endphp
                    <button
                        wire:click="selectTrip({{ $trip->id }})"
                        @disabled($isFull)
                        @class([
                            'w-full text-right p-4 rounded-xl border-2 transition-all duration-200 text-start',
                            'border-primary-500 bg-primary-50 dark:bg-primary-900/30 shadow-md scale-[1.02]' => $isSelected,
                            'border-gray-200 dark:border-gray-700 hover:border-primary-300 hover:bg-gray-50 dark:hover:bg-gray-800' => !$isSelected && !$isFull,
                            'border-gray-100 dark:border-gray-800 opacity-50 cursor-not-allowed' => $isFull,
                        ])
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1">
                                <div class="font-bold text-gray-900 dark:text-white text-base">
                                    {{ $trip->tripTemplate->title ?? 'رحلة بدون اسم' }}
                                </div>
                                <div class="text-sm text-gray-500 mt-1">
                                    📅 {{ \Carbon\Carbon::parse($trip->start_date)->format('d M Y') }}
                                    → {{ \Carbon\Carbon::parse($trip->end_date)->format('d M Y') }}
                                </div>
                            </div>
                            <div @class([
                                'px-2 py-1 rounded-lg text-xs font-bold whitespace-nowrap',
                                'bg-green-100 text-green-700' => $remaining > 5,
                                'bg-amber-100 text-amber-700' => $remaining > 0 && $remaining <= 5,
                                'bg-red-100 text-red-700' => $isFull,
                            ])>
                                @if($isFull) مكتملة @else {{ $remaining }} مقعد @endif
                            </div>
                        </div>
                        @if($isSelected)
                            <div class="mt-2 text-primary-600 dark:text-primary-400 text-xs font-medium">✓ محددة</div>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </div>
    @endif

    {{-- ──────────────────── Step 3: الركاب ──────────────────── --}}
    @if($currentStep === 3)
    <div class="fi-section rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">👥 الخطوة 3: بيانات الركاب</h2>
        <p class="text-sm text-gray-500 mb-6">أضف بيانات كل راكب. الاسم والفئة إلزاميان.</p>

        @php $categories = $this->getPassengerCategories(); @endphp

        <div class="space-y-4">
            @foreach($passengers as $index => $passenger)
                <div class="p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                    <div class="flex items-center justify-between mb-3">
                        <span class="font-semibold text-gray-700 dark:text-gray-300">الراكب {{ $index + 1 }}</span>
                        @if(count($passengers) > 1)
                            <button wire:click="removePassenger({{ $index }})" class="text-red-400 hover:text-red-600 text-xs transition-colors">
                                🗑 حذف
                            </button>
                        @endif
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">الاسم الأول *</label>
                            <input type="text" wire:model.live="passengers.{{ $index }}.first_name"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-3 py-2 text-sm" dir="rtl"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">اسم العائلة</label>
                            <input type="text" wire:model.live="passengers.{{ $index }}.last_name"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-3 py-2 text-sm" dir="rtl"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">الفئة والسعر *</label>
                            <select wire:model.live="passengers.{{ $index }}.trip_passenger_category_id"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-3 py-2 text-sm" dir="rtl">
                                <option value="">اختر الفئة...</option>
                                @foreach($categories as $catId => $catName)
                                    <option value="{{ $catId }}" @selected(($passenger['trip_passenger_category_id'] ?? null) == $catId)>{{ $catName }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">نوع الوثيقة</label>
                            <select wire:model.live="passengers.{{ $index }}.document_type"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-3 py-2 text-sm" dir="rtl">
                                <option value="national_id">هوية وطنية</option>
                                <option value="passport">جواز سفر</option>
                            </select>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">رقم الوثيقة</label>
                            <input type="text" wire:model.live="passengers.{{ $index }}.document_number"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white px-3 py-2 text-sm" dir="ltr"/>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <button wire:click="addPassenger"
            class="mt-4 w-full py-3 border-2 border-dashed border-primary-300 dark:border-primary-700 rounded-xl text-primary-600 dark:text-primary-400 hover:bg-primary-50 dark:hover:bg-primary-900/20 transition-colors text-sm font-medium">
            + إضافة راكب آخر
        </button>

        {{-- Total --}}
        <div class="mt-4 p-4 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-between">
            <span class="text-gray-600 dark:text-gray-400 font-medium">الإجمالي المتوقع:</span>
            <span class="text-2xl font-bold text-primary-600 dark:text-primary-400">
                $ {{ number_format($this->getGrandTotal(), 2) }}
            </span>
        </div>
    </div>
    @endif

    {{-- ──────────────────── Step 4: الدفع ──────────────────── --}}
    @if($currentStep === 4)
    <div class="fi-section rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-1">💳 الخطوة 4: معلومات الدفع</h2>
        <p class="text-sm text-gray-500 mb-6">هذه الخطوة اختيارية — يمكن تسجيل الدفع لاحقاً من صفحة الحجز.</p>

        {{-- Booking Summary --}}
        @php
            $trip = $this->getSelectedTrip();
            $total = $this->getGrandTotal();
        @endphp
        <div class="p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 mb-6">
            <div class="text-sm text-blue-800 dark:text-blue-200 space-y-1">
                <div><span class="font-medium">العميل:</span> {{ $customer_name }}</div>
                <div><span class="font-medium">الرحلة:</span> {{ $trip?->tripTemplate?->title }}</div>
                <div><span class="font-medium">عدد الركاب:</span> {{ count($passengers) }}</div>
                <div><span class="font-medium">الإجمالي:</span> <strong class="text-lg">$ {{ number_format($total, 2) }}</strong></div>
            </div>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">طريقة الدفع</label>
            <div class="grid grid-cols-3 gap-3">
                @foreach(['cash' => '💵 نقداً', 'transfer' => '🏦 تحويل', 'card' => '💳 بطاقة'] as $method => $label)
                    <button
                        wire:click="$set('payment_method', '{{ $method }}')"
                        @class([
                            'py-3 rounded-xl border-2 text-sm font-medium transition-all',
                            'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300' => $payment_method === $method,
                            'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:border-gray-300' => $payment_method !== $method,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">ملاحظات (اختياري)</label>
            <textarea wire:model.live="notes" rows="3"
                class="w-full rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white px-4 py-3 text-sm"
                dir="rtl" placeholder="أي ملاحظات خاصة بهذا الحجز..."></textarea>
        </div>
    </div>
    @endif

    {{-- ──────────────────── Step 5: التأكيد ──────────────────── --}}
    @if($currentStep === 5)
    <div class="fi-section rounded-xl border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20 shadow-sm p-8 text-center">
        <div class="text-6xl mb-4">🎉</div>
        <h2 class="text-2xl font-bold text-green-800 dark:text-green-200 mb-2">تم إنشاء الحجز بنجاح!</h2>
        <p class="text-green-600 dark:text-green-400 mb-6">رقم الحجز (PNR):</p>
        <div class="inline-block px-8 py-4 bg-white dark:bg-gray-900 rounded-2xl border-2 border-green-400 shadow-lg mb-8">
            <span class="text-4xl font-black tracking-widest text-green-700 dark:text-green-300 font-mono">{{ $pnr }}</span>
        </div>
        <div class="flex gap-3 justify-center">
            <button wire:click="viewBooking"
                class="px-6 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm transition-colors">
                📄 عرض تفاصيل الحجز
            </button>
            <button wire:click="startNewBooking"
                class="px-6 py-3 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-xl font-medium text-sm transition-colors">
                ➕ حجز جديد
            </button>
        </div>
    </div>
    @endif

    {{-- ──────────────────── Navigation Buttons ──────────────────── --}}
    @if($currentStep < 5)
    <div class="flex items-center justify-between mt-4">
        <button
            wire:click="prevStep"
            @class([
                'px-6 py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 font-medium text-sm transition-colors',
                'invisible' => $currentStep === 1,
            ])
        >
            ← رجوع
        </button>

        <span class="text-xs text-gray-400">الخطوة {{ $currentStep }} من 4</span>

        @if($currentStep === 4)
            <button
                wire:click="submitBooking"
                wire:loading.attr="disabled"
                class="px-8 py-3 bg-green-500 hover:bg-green-600 disabled:opacity-50 text-white rounded-xl font-bold text-sm transition-colors flex items-center gap-2"
            >
                <span wire:loading.remove wire:target="submitBooking">✅ إتمام الحجز</span>
                <span wire:loading wire:target="submitBooking">⏳ جارٍ الحفظ...</span>
            </button>
        @else
            <button
                wire:click="nextStep"
                class="px-8 py-3 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-bold text-sm transition-colors"
            >
                التالي →
            </button>
        @endif
    </div>
    @endif

</x-filament-panels::page>
