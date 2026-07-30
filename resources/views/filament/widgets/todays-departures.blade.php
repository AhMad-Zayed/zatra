<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            رحلات اليوم — {{ now()->format('d/m/Y') }}
        </x-slot>
        <x-slot name="headerEnd">
            <span class="text-sm text-gray-400">{{ now()->format('l') }}</span>
        </x-slot>

        @php $departures = $this->getTodaysDepartures() @endphp

        @if($departures->isEmpty())
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <div style="width: 4rem; height: 4rem; margin: 0 auto 0.75rem auto;">
                    <x-heroicon-o-calendar-days class="text-gray-300 dark:text-gray-600 w-full h-full"/>
                </div>
                <p class="text-gray-500 dark:text-gray-400 font-medium">لا توجد رحلات مجدولة اليوم</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">تحقق من التقويم للرحلات القادمة</p>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-2">
                @foreach($departures as $dep)
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700 shadow-sm hover:shadow-md transition-shadow">
                        {{-- Trip header --}}
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex-1 min-w-0 mr-2">
                                <h3 class="font-bold text-gray-900 dark:text-white text-sm leading-tight truncate">
                                    {{ $dep['title'] }}
                                </h3>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                    ⏰ {{ $dep['time'] }}
                                </p>
                            </div>
                            @if($dep['fill_rate'] >= 90)
                                <span class="text-xs font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 px-2 py-1 rounded-full whitespace-nowrap">
                                    مكتملة
                                </span>
                            @elseif($dep['fill_rate'] >= 70)
                                <span class="text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 px-2 py-1 rounded-full whitespace-nowrap">
                                    شبه ممتلئة
                                </span>
                            @else
                                <span class="text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-2 py-1 rounded-full whitespace-nowrap">
                                    متاحة
                                </span>
                            @endif
                        </div>

                        {{-- Occupancy progress --}}
                        <div class="mb-4">
                            <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                                <span>نسبة الإشغال</span>
                                <span class="font-semibold">{{ $dep['confirmed'] }} / {{ $dep['capacity'] }} راكب</span>
                            </div>
                            <div class="w-full bg-gray-100 dark:bg-gray-700 rounded-full h-2.5 overflow-hidden">
                                <div
                                    class="h-2.5 rounded-full transition-all duration-300 {{ $dep['fill_rate'] >= 90 ? 'bg-red-500' : ($dep['fill_rate'] >= 70 ? 'bg-amber-400' : 'bg-emerald-500') }}"
                                    style="width: {{ min(100, $dep['fill_rate']) }}%"
                                ></div>
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 text-left ltr">
                                {{ $dep['fill_rate'] }}%
                            </p>
                        </div>

                        {{-- Unpaid warning --}}
                        @if($dep['unpaid_count'] > 0)
                            <div class="flex items-center gap-2 text-xs bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400 px-3 py-2 rounded-lg mb-3 border border-red-100 dark:border-red-800">
                                <x-heroicon-s-exclamation-triangle class="w-4 h-4 flex-shrink-0"/>
                                <span>{{ $dep['unpaid_count'] }} حجز غير مدفوع</span>
                            </div>
                        @endif

                        {{-- Manifest link --}}
                        <a
                            href="{{ $dep['manifest_url'] }}"
                            target="_blank"
                            class="mt-2 flex items-center justify-center gap-2 w-full text-xs font-medium bg-gray-900 dark:bg-gray-700 text-white py-2.5 rounded-lg hover:bg-gray-700 dark:hover:bg-gray-600 transition-colors"
                        >
                            <x-heroicon-s-document-text class="w-4 h-4"/>
                            طباعة كشف الركاب
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
