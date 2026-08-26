<x-filament-panels::page>
    @php
        $totals = $this->currencyTotals();
    @endphp

    @if ($totals->isNotEmpty())
        <div class="mb-4 flex flex-wrap gap-3">
            @foreach ($totals as $row)
                <div class="fi-section rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-white/10 dark:bg-gray-900">
                    <div class="text-xs text-gray-400">إجمالي بانتظار الاسترداد — {{ $row['currency'] }}</div>
                    <div class="mt-0.5 text-lg font-semibold text-gray-900 dark:text-white">
                        {{ number_format($row['total'], 2) }} {{ $row['currency'] }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
