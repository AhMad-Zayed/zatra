@php
    $paymentMethodLabels = ['cash' => 'نقدي', 'bank_transfer' => 'تحويل بنكي'];
@endphp

@if ($payments->isEmpty())
    <p class="text-sm text-gray-400">لا توجد دفعات مسجلة لهذا الحجز.</p>
@else
    <div class="space-y-2">
        @foreach ($payments->sortByDesc('created_at') as $payment)
            <div class="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm dark:border-white/10">
                <div>
                    <div class="font-medium text-gray-900 dark:text-white">
                        {{ $paymentMethodLabels[$payment->payment_method] ?? $payment->payment_method }}
                    </div>
                    <div class="mt-0.5 text-xs text-gray-400">
                        {{ $payment->created_at->format('Y-m-d H:i') }}
                        @if ($payment->reference_number)
                            — {{ $payment->reference_number }}
                        @endif
                    </div>
                </div>
                <span class="font-semibold text-gray-900 dark:text-white">
                    {{ number_format($payment->amount, 2) }} {{ $payment->currency }}
                </span>
            </div>
        @endforeach
    </div>
@endif
