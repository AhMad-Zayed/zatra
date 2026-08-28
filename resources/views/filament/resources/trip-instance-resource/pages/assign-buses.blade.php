<x-filament-panels::page>
    @php
        $buses = $this->buses();
        $totalCapacity = $this->totalCapacity();
        $board = $this->boardData();
    @endphp

    <div
        x-data="{
            initSortable(el) {
                if (el.sortableInstance) return;
                el.sortableInstance = Sortable.create(el, {
                    group: 'buses',
                    animation: 150,
                    ghostClass: 'ba-ghost',
                    dragClass: 'ba-drag',
                    onAdd: (evt) => {
                        const passengerId = evt.item.dataset.passengerId;
                        const targetBusId = evt.to.dataset.busId;
                        if (targetBusId) {
                            $wire.dropPassenger(parseInt(passengerId), parseInt(targetBusId));
                        } else {
                            $wire.removeFromBus(parseInt(passengerId));
                        }
                    },
                });
            },
            initAll() {
                this.$el.querySelectorAll('[data-sortable-zone]').forEach((el) => this.initSortable(el));
            },
        }"
        x-init="initAll(); Livewire.hook('morph.updated', ({ el }) => { $nextTick(() => initAll()); });"
    >
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-heroicon-o-users class="h-4 w-4" />
                <span>إجمالي السعة: <span class="font-semibold text-gray-900 dark:text-white">{{ $totalCapacity }}</span> مقعد عبر {{ $buses->count() }} {{ $buses->count() === 1 ? 'حافلة' : 'حافلات' }}</span>
            </div>

            @if ($buses->isNotEmpty())
                <button
                    type="button"
                    wire:click="runAutoAssign"
                    wire:loading.attr="disabled"
                    class="flex items-center gap-2 rounded-lg bg-accent-500 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-600 disabled:opacity-60"
                >
                    <x-heroicon-o-sparkles class="h-4 w-4" />
                    تخصيص تلقائي
                </button>
            @endif
        </div>

        @if ($buses->isEmpty())
            <div class="fi-section flex flex-col items-center justify-center rounded-xl border border-gray-200 bg-white p-12 text-center dark:border-white/10 dark:bg-gray-900">
                <x-heroicon-o-truck class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="mt-3 font-medium text-gray-500 dark:text-gray-400">لم يتم تخصيص أي حافلة لهذه الرحلة بعد</p>
                <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">استخدم زر "إضافة حافلة" أعلاه للبدء.</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-5 lg:grid-cols-4">
                {{-- Unassigned pool --}}
                <div class="lg:col-span-1">
                    <div class="fi-section rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-white/10">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">غير مخصصين</h3>
                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                {{ $board['unassigned']->count() }}
                            </span>
                        </div>
                        <div
                            data-sortable-zone
                            class="min-h-[120px] space-y-2 p-3"
                        >
                            @forelse ($board['unassigned']->sortBy('booking_id') as $passenger)
                                <div
                                    wire:key="passenger-{{ $passenger->id }}"
                                    data-passenger-id="{{ $passenger->id }}"
                                    class="cursor-grab rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm active:cursor-grabbing dark:border-white/10 dark:bg-gray-800"
                                >
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $passenger->display_name }}</div>
                                    <div class="mt-0.5 flex items-center gap-1.5 text-xs text-gray-400">
                                        <span>{{ $passenger->booking?->pnr }}</span>
                                        @if ($passenger->seat_number)
                                            <span class="rounded bg-warning-50 px-1.5 py-0.5 font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400" title="مقعد سابق قبل إضافة حافلة أخرى — يحتاج تأكيد">
                                                مقعد سابق: {{ $passenger->seat_number }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @empty
                                <p class="py-6 text-center text-xs text-gray-400">لا يوجد ركاب بانتظار التخصيص</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- Buses --}}
                <div class="grid grid-cols-1 gap-4 lg:col-span-3 lg:grid-cols-2">
                    @foreach ($buses as $bus)
                        @php
                            $occupants = $board['buses']->firstWhere('id', $bus->id)?->passengers ?? collect();
                            $isFull = $occupants->count() >= $bus->capacity;
                        @endphp
                        <div wire:key="bus-{{ $bus->id }}" class="fi-section overflow-hidden rounded-xl border bg-white dark:bg-gray-900 {{ $isFull ? 'border-success-300 dark:border-success-800' : 'border-gray-200 dark:border-white/10' }}">
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
                                    <span @class([
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $isFull,
                                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => !$isFull,
                                    ])>
                                        {{ $occupants->count() }}/{{ $bus->capacity }}
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
                                    <div class="text-xs text-gray-400">السائق</div>
                                    <div class="mt-0.5 flex items-center gap-1.5 font-medium text-gray-900 dark:text-white">
                                        {{ $bus->driver_display_name }}
                                        @if ($bus->driver_type?->value === 'external')
                                            <span class="text-xs font-normal text-gray-400">({{ $bus->driver_phone }})</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="col-span-2">
                                    <div class="text-xs text-gray-400">المرشد</div>
                                    <div class="mt-0.5 flex items-center gap-1.5 font-medium text-gray-900 dark:text-white">
                                        {{ $bus->guide_display_name }}
                                        @if ($bus->guide_type?->value === 'external')
                                            <span class="text-xs font-normal text-gray-400">({{ $bus->guide_phone }})</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <div
                                data-sortable-zone
                                data-bus-id="{{ $bus->id }}"
                                class="min-h-[90px] space-y-2 border-t border-gray-100 p-3 dark:border-white/10"
                            >
                                @foreach ($occupants->sortBy(fn ($p) => (int) $p->seat_number) as $passenger)
                                    <div
                                        wire:key="passenger-{{ $passenger->id }}"
                                        data-passenger-id="{{ $passenger->id }}"
                                        class="flex cursor-grab items-center justify-between rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm active:cursor-grabbing dark:border-white/10 dark:bg-gray-800"
                                    >
                                        <div>
                                            <div class="font-medium text-gray-900 dark:text-white">{{ $passenger->display_name }}</div>
                                            <div class="mt-0.5 text-xs text-gray-400">{{ $passenger->booking?->pnr }}</div>
                                        </div>
                                        <span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                                            مقعد {{ $passenger->seat_number }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <style>
        .ba-ghost { opacity: 0.4; }
        .ba-drag { cursor: grabbing !important; }
    </style>
</x-filament-panels::page>
