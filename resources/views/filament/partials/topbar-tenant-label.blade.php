@php
    $tenant = \Filament\Facades\Filament::getTenant();
@endphp

@if ($tenant)
    <div class="hidden items-center gap-x-2 lg:flex">
        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400">
            <x-heroicon-o-building-office-2 class="h-4 w-4" />
        </span>
        <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $tenant->name }}
        </span>
    </div>
@endif
