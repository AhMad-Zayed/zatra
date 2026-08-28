<x-filament-panels::page>
    @php
        $options = $this->hotelOptions();
        $selected = $this->selectedHotelOption();
        $board = $this->boardData();
    @endphp

    @if ($options->isEmpty())
        <div class="fi-section flex flex-col items-center justify-center rounded-xl border border-gray-200 bg-white p-12 text-center dark:border-white/10 dark:bg-gray-900">
            <x-heroicon-o-building-office-2 class="h-10 w-10 text-gray-300 dark:text-gray-600" />
            <p class="mt-3 font-medium text-gray-500 dark:text-gray-400">لا توجد فنادق قيد الحجز لهذه الرحلة</p>
            <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">لم يقم أي عميل باختيار غرفة إقامة لهذه الرحلة بعد.</p>
        </div>
    @else
        <div
            x-data="{
                initSortable(el) {
                    if (el.sortableInstance) return;
                    el.sortableInstance = Sortable.create(el, {
                        group: 'rooms',
                        animation: 150,
                        ghostClass: 'ra-ghost',
                        dragClass: 'ra-drag',
                        onAdd: (evt) => {
                            const passengerId = evt.item.dataset.passengerId;
                            const targetRoomId = evt.to.dataset.roomId;
                            if (targetRoomId) {
                                $wire.dropPassenger(parseInt(passengerId), parseInt(targetRoomId));
                            } else {
                                $wire.removeFromRoom(parseInt(passengerId));
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
            {{-- Hotel option selector + actions --}}
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                @if ($options->count() > 1)
                    <div class="flex flex-wrap gap-2">
                        @foreach ($options as $option)
                            <button
                                type="button"
                                wire:click="selectHotelOption({{ $option->id }})"
                                @class([
                                    'rounded-full px-4 py-1.5 text-sm font-medium transition-colors',
                                    'bg-primary-600 text-white' => $selected?->id === $option->id,
                                    'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300' => $selected?->id !== $option->id,
                                ])
                            >
                                {{ $option->hotel->name }}
                            </button>
                        @endforeach
                    </div>
                @else
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ $selected->hotel->name }}</h2>
                @endif

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        wire:click="runAutoAssign"
                        wire:loading.attr="disabled"
                        class="flex items-center gap-2 rounded-lg bg-accent-500 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-600 disabled:opacity-60"
                    >
                        <x-heroicon-o-sparkles class="h-4 w-4" />
                        تخصيص تلقائي
                    </button>

                    @if ($this->roomingListUrl())
                        <a
                            href="{{ $this->roomingListUrl() }}"
                            target="_blank"
                            class="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-white/5"
                        >
                            <x-heroicon-o-printer class="h-4 w-4" />
                            طباعة قائمة الغرف
                        </a>
                    @endif
                </div>
            </div>

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
                            x-on:dragenter.prevent="$el.classList.add('ra-zone-active')"
                            x-on:dragover.prevent
                            x-on:dragleave="$el.classList.remove('ra-zone-active')"
                            x-on:drop="$el.classList.remove('ra-zone-active')"
                        >
                            @forelse ($board['unassigned']->sortBy('booking_id') as $passenger)
                                <div
                                    wire:key="passenger-{{ $passenger->id }}"
                                    data-passenger-id="{{ $passenger->id }}"
                                    class="cursor-grab rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm active:cursor-grabbing dark:border-white/10 dark:bg-gray-800"
                                >
                                    <div class="font-medium text-gray-900 dark:text-white">{{ $passenger->display_name }}</div>
                                    <div class="mt-0.5 text-xs text-gray-400">{{ $passenger->booking?->pnr }}</div>
                                </div>
                            @empty
                                <p class="py-6 text-center text-xs text-gray-400">لا يوجد ركاب بانتظار التخصيص</p>
                            @endforelse
                        </div>
                    </div>
                </div>

                {{-- Room board --}}
                <div class="space-y-6 lg:col-span-3">
                    @foreach ($board['roomTypes'] as $roomType)
                        <div>
                            <h3 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                                {{ $roomType->name }}
                                <span class="font-normal text-gray-400">— سعة {{ $roomType->capacity_per_room }} لكل غرفة</span>
                            </h3>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach ($roomType->roomInstances as $room)
                                    @php $occupants = $room->assignments; $isFull = $occupants->count() >= $roomType->capacity_per_room; @endphp
                                    <div
                                        wire:key="room-{{ $room->id }}"
                                        class="fi-section rounded-xl border bg-white dark:bg-gray-900 {{ $isFull ? 'border-success-300 dark:border-success-800' : 'border-gray-200 dark:border-white/10' }}"
                                    >
                                        <div class="flex items-center justify-between border-b border-gray-100 px-3 py-2 dark:border-white/10">
                                            <span class="text-sm font-medium text-gray-800 dark:text-gray-100">غرفة {{ $room->room_number }}</span>
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-xs font-semibold',
                                                'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' => $isFull,
                                                'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => !$isFull,
                                            ])>
                                                {{ $occupants->count() }}/{{ $roomType->capacity_per_room }}
                                            </span>
                                        </div>
                                        <div
                                            data-sortable-zone
                                            data-room-id="{{ $room->id }}"
                                            class="min-h-[90px] space-y-2 rounded-b-xl border-t-2 border-dashed border-gray-200 bg-gray-50/60 p-3 dark:border-white/10 dark:bg-white/[0.02]"
                                            x-on:dragenter.prevent="$el.classList.add('ra-zone-active')"
                                            x-on:dragover.prevent
                                            x-on:dragleave="$el.classList.remove('ra-zone-active')"
                                            x-on:drop="$el.classList.remove('ra-zone-active')"
                                        >
                                            @forelse ($occupants as $assignment)
                                                <div
                                                    wire:key="passenger-{{ $assignment->passenger->id }}"
                                                    data-passenger-id="{{ $assignment->passenger->id }}"
                                                    class="cursor-grab rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm active:cursor-grabbing dark:border-white/10 dark:bg-gray-800"
                                                >
                                                    <div class="font-medium text-gray-900 dark:text-white">{{ $assignment->passenger->display_name }}</div>
                                                    <div class="mt-0.5 text-xs text-gray-400">{{ $assignment->passenger->booking?->pnr }}</div>
                                                </div>
                                            @empty
                                                <p class="pointer-events-none py-4 text-center text-xs text-gray-400">اسحب راكباً هنا</p>
                                            @endforelse
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <style>
        .ra-ghost { opacity: 0.4; }
        .ra-drag { cursor: grabbing !important; }
        {{-- Quick win: the drop zone (the card's content region below the header) was
             indistinguishable from the rest of the card until a drop had already been attempted
             on it -- a drag hovering the header/badge area silently did nothing, with no visual
             cue either way. This highlights the exact region a drop will register on the moment
             a drag enters it, matching the audit's suggested "or a visual highlight on hover"
             remedy without moving data-sortable-zone onto the whole card (which would risk
             SortableJS trying to sort the header's own children). --}}
        [data-sortable-zone].ra-zone-active {
            outline: 2px dashed rgb(37 99 235);
            outline-offset: -2px;
            background-color: rgb(239 246 255);
        }
        :root.dark [data-sortable-zone].ra-zone-active,
        .dark [data-sortable-zone].ra-zone-active {
            background-color: rgb(30 58 138 / 0.25);
        }
    </style>
</x-filament-panels::page>
