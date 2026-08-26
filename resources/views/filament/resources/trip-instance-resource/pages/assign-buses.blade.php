<x-filament-panels::page>
    @php
        $buses = $this->buses();
        $totalCapacity = $this->totalCapacity();
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <x-heroicon-o-users class="h-4 w-4" />
            <span>إجمالي السعة: <span class="font-semibold text-gray-900 dark:text-white">{{ $totalCapacity }}</span> مقعد عبر {{ $buses->count() }} {{ $buses->count() === 1 ? 'حافلة' : 'حافلات' }}</span>
        </div>
    </div>

    @if ($buses->isEmpty())
        <div class="fi-section flex flex-col items-center justify-center rounded-xl border border-gray-200 bg-white p-12 text-center dark:border-white/10 dark:bg-gray-900">
            <x-heroicon-o-truck class="h-10 w-10 text-gray-300 dark:text-gray-600" />
            <p class="mt-3 font-medium text-gray-500 dark:text-gray-400">لم يتم تخصيص أي حافلة لهذه الرحلة بعد</p>
            <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">استخدم زر "إضافة حافلة" أعلاه للبدء.</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach ($buses as $bus)
                <div wire:key="bus-{{ $bus->id }}" class="fi-section overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                    <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-white/10">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                حافلة {{ $loop->iteration }}
                            </span>
                            <span @class([
                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $bus->ownership_type->value === 'owned',
                                'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $bus->ownership_type->value === 'rented',
                            ])>
                                {{ $bus->ownership_type->getLabel() }}
                            </span>
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                wire:click="mountAction('editBus', { id: {{ $bus->id }} })"
                                class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/5 dark:hover:text-gray-300"
                                title="تعديل"
                            >
                                <x-heroicon-o-pencil class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                wire:click="mountAction('deleteBus', { id: {{ $bus->id }} })"
                                class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-500/10 dark:hover:text-danger-400"
                                title="حذف"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4 p-4 text-sm">
                        <div>
                            <div class="text-xs text-gray-400">
                                {{ $bus->ownership_type->value === 'owned' ? 'رقم اللوحة' : 'شركة التأجير' }}
                            </div>
                            <div class="mt-0.5 font-medium text-gray-900 dark:text-white">
                                {{ $bus->ownership_type->value === 'owned' ? ($bus->vehicle?->plate_number ?? '—') : ($bus->rented_supplier_name ?? '—') }}
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">السعة</div>
                            <div class="mt-0.5 font-medium text-gray-900 dark:text-white">{{ $bus->capacity }} مقعد</div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">السائق</div>
                            <div class="mt-0.5 flex items-center gap-1.5 font-medium text-gray-900 dark:text-white">
                                {{ $bus->driver_display_name }}
                                @if ($bus->driver_type->value === 'external')
                                    <span class="text-xs font-normal text-gray-400">({{ $bus->driver_phone }})</span>
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-xs text-gray-400">المرشد</div>
                            <div class="mt-0.5 flex items-center gap-1.5 font-medium text-gray-900 dark:text-white">
                                {{ $bus->guide_display_name }}
                                @if ($bus->guide_type->value === 'external')
                                    <span class="text-xs font-normal text-gray-400">({{ $bus->guide_phone }})</span>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
