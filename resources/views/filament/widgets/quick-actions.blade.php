<x-filament-widgets::widget>
    @if ($this->isFreshTenant())
        <div class="mb-4 flex items-center gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-800 dark:border-primary-500/20 dark:bg-primary-500/10 dark:text-primary-300">
            <x-heroicon-o-sparkles class="h-5 w-5 shrink-0" />
            <span>مرحباً بك! ابدأ بإنشاء أول رحلة لك لتظهر بيانات لوحة التحكم — استخدم بطاقة "رحلة جديدة" أدناه.</span>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">

        {{-- New Booking — the one accent-colored tile per spec ("used sparingly") --}}
        <a href="{{ \App\Filament\Resources\BookingResource::getUrl('create') }}"
            class="group flex flex-col items-center justify-center gap-3 rounded-lg bg-accent-500 px-4 py-6 text-white transition-colors hover:bg-accent-600">
            <x-heroicon-o-ticket class="h-7 w-7" />
            <span class="text-sm font-semibold">حجز جديد</span>
        </a>

        <a href="{{ \App\Filament\Resources\TripInstanceResource::getUrl('create') }}"
            class="group flex flex-col items-center justify-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-6 text-gray-700 transition-colors hover:border-primary-300 hover:bg-primary-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            <x-heroicon-o-map class="h-7 w-7 text-primary-600 dark:text-primary-400" />
            <span class="text-sm font-semibold">رحلة جديدة</span>
        </a>

        <a href="{{ \App\Filament\Resources\BookingResource::getUrl('index') }}"
            class="group flex flex-col items-center justify-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-6 text-gray-700 transition-colors hover:border-primary-300 hover:bg-primary-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            <x-heroicon-o-magnifying-glass class="h-7 w-7 text-primary-600 dark:text-primary-400" />
            <span class="text-sm font-semibold">بحث الحجوزات</span>
        </a>

        <a href="{{ \App\Filament\Resources\CustomerResource::getUrl('index') }}"
            class="group flex flex-col items-center justify-center gap-3 rounded-lg border border-gray-200 bg-white px-4 py-6 text-gray-700 transition-colors hover:border-primary-300 hover:bg-primary-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5">
            <x-heroicon-o-users class="h-7 w-7 text-primary-600 dark:text-primary-400" />
            <span class="text-sm font-semibold">العملاء</span>
        </a>

    </div>
</x-filament-widgets::widget>
