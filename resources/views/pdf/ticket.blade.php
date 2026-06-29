<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zatara Ticket - {{ $booking->pnr }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background-color: #ffffff;
            color: #000000;
        }
        .print-break-inside-avoid {
            break-inside: avoid;
        }
        .dashed-divider {
            border-top: 2px dashed #e2e8f0;
            margin: 2rem 0;
        }
    </style>
</head>
<body class="p-8 max-w-4xl mx-auto relative">
    @if($booking->payment_status === \App\Enums\PaymentStatus::Unpaid)
    <div style="position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg); font-size: 80px; color: rgba(239, 68, 68, 0.15); font-weight: 900; white-space: nowrap; pointer-events: none; z-index: 10; border: 8px solid rgba(239, 68, 68, 0.15); padding: 20px;">
        UNPAID / غير مدفوع
    </div>
    @endif
    <!-- Header -->
    <header class="flex justify-between items-center mb-10 border-b-4 border-slate-900 pb-6">
        <div>
            <h1 class="text-4xl font-extrabold tracking-tight mb-2">تذكرة سفر مبدئية</h1>
            <p class="text-xl text-slate-600 font-medium">Zatara Tours & Travel</p>
        </div>
        <div class="text-left bg-slate-100 p-4 rounded-xl border border-slate-300">
            <p class="text-sm text-slate-500 font-bold uppercase tracking-widest mb-1">الرقم المرجعي <span dir="ltr">(PNR)</span></p>
            <p class="text-3xl font-extrabold font-mono tracking-widest">{{ $booking->pnr }}</p>
        </div>
    </header>

    <!-- Main Content -->
    <main class="grid grid-cols-1 gap-8">
        
        <!-- Booking & Customer Info -->
        <section class="grid grid-cols-2 gap-8 print-break-inside-avoid">
            <div class="border-2 border-slate-200 rounded-2xl p-6 bg-slate-50">
                <h2 class="text-lg font-bold text-slate-400 uppercase tracking-widest mb-4">معلومات العميل الأساسي</h2>
                <div class="space-y-3">
                    <div>
                        <p class="text-sm text-slate-500">الاسم:</p>
                        <p class="text-xl font-bold">{{ $booking->customer->name ?? ($booking->passengers->first()->dynamic_data['name'] ?? 'غير متوفر') }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-500">رقم الهاتف:</p>
                        <p class="text-lg font-medium font-mono" dir="ltr">{{ $booking->customer->phone ?? 'غير متوفر' }}</p>
                    </div>
                </div>
            </div>

            <div class="border-2 border-slate-200 rounded-2xl p-6 bg-slate-50">
                <h2 class="text-lg font-bold text-slate-400 uppercase tracking-widest mb-4">حالة الحجز والدفع</h2>
                <div class="space-y-3">
                    <div class="flex justify-between items-center border-b border-slate-200 pb-2">
                        <p class="text-slate-600">حالة الحجز:</p>
                        <span class="px-3 py-1 bg-yellow-100 text-yellow-800 font-bold rounded-lg text-sm">
                            {{ $booking->booking_status->getLabel() ?? 'قيد الانتظار' }}
                        </span>
                    </div>
                    <div class="flex justify-between items-center border-b border-slate-200 pb-2">
                        <p class="text-slate-600">المبلغ الإجمالي:</p>
                        <p class="text-xl font-extrabold font-mono">${{ number_format($booking->grand_total, 2) }}</p>
                    </div>
                    @if($booking->expires_at)
                    <div class="flex justify-between items-center pt-1 text-red-600">
                        <p class="font-bold">ينتهي الحجز في:</p>
                        <p class="font-bold font-mono" dir="ltr">{{ \Carbon\Carbon::parse($booking->expires_at)->format('Y-m-d h:i A') }}</p>
                    </div>
                    @endif
                </div>
            </div>
        </section>

        <!-- Trip Info -->
        <section class="border-2 border-slate-900 rounded-2xl p-6 print-break-inside-avoid">
            <h2 class="text-2xl font-bold mb-6 border-b-2 border-slate-100 pb-4">تفاصيل الرحلة</h2>
            
            <div class="mb-6">
                <h3 class="text-xl font-extrabold text-slate-800 mb-2">{{ $tripInstance->tripTemplate->title ?? 'الرحلة' }}</h3>
                <p class="text-slate-500">{{ $tripInstance->tripTemplate->destination ?? '' }}</p>
            </div>

            <div class="grid grid-cols-2 gap-6 bg-slate-50 p-4 rounded-xl border border-slate-200">
                <div>
                    <p class="text-sm text-slate-500 font-bold mb-1">تاريخ المغادرة</p>
                    <p class="text-xl font-bold font-mono" dir="ltr">{{ \Carbon\Carbon::parse($tripInstance->start_date)->format('Y-m-d') }}</p>
                </div>
                <div>
                    <p class="text-sm text-slate-500 font-bold mb-1">تاريخ العودة</p>
                    <p class="text-xl font-bold font-mono" dir="ltr">{{ \Carbon\Carbon::parse($tripInstance->end_date)->format('Y-m-d') }}</p>
                </div>
            </div>
        </section>

        <!-- Passengers List -->
        <section class="print-break-inside-avoid">
            <h2 class="text-2xl font-bold mb-4">قائمة المسافرين <span dir="ltr">({{ $booking->passengers->count() }})</span></h2>
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="bg-slate-100 border-y-2 border-slate-300">
                        <th class="py-3 px-4 font-bold text-slate-600 w-12 text-center">#</th>
                        <th class="py-3 px-4 font-bold text-slate-600">اسم المسافر</th>
                        <th class="py-3 px-4 font-bold text-slate-600">الفئة العمرية / الباقة</th>
                        <th class="py-3 px-4 font-bold text-slate-600 text-left">التكلفة</th>
                    </tr>
                </thead>
                <tbody class="divide-y border-b-2 border-slate-300">
                    @foreach($booking->passengers as $index => $passenger)
                    <tr class="hover:bg-slate-50">
                        <td class="py-4 px-4 text-center font-bold text-slate-400">{{ $index + 1 }}</td>
                        <td class="py-4 px-4 font-bold text-lg">{{ $passenger->dynamic_data['name'] ?? 'غير محدد' }}</td>
                        <td class="py-4 px-4 text-slate-600"><bdi>{{ $passenger->tripPricingTier->name ?? 'أساسي' }}</bdi></td>
                        <td class="py-4 px-4 font-bold font-mono text-left">${{ number_format($passenger->price_at_booking, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <!-- Addons (If any) -->
        @if($booking->bookingAddons && $booking->bookingAddons->count() > 0)
        <section class="print-break-inside-avoid">
            <h2 class="text-xl font-bold mb-4 mt-4">الخدمات الإضافية</h2>
            <table class="w-full text-right border-collapse">
                <tbody class="divide-y border-b-2 border-t-2 border-slate-300">
                    @foreach($booking->bookingAddons as $addon)
                    <tr>
                        <td class="py-3 px-4 font-medium">{{ $addon->tripAddon->name ?? 'خدمة' }} (x{{ $addon->quantity }})</td>
                        <td class="py-3 px-4 font-bold font-mono text-left">${{ number_format($addon->total_price, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
        @endif

        <div class="dashed-divider"></div>

        <!-- Footer / QR & Terms -->
        <section class="grid grid-cols-3 gap-8 items-center print-break-inside-avoid">
            <!-- QR Code -->
            <div class="col-span-1 flex flex-col items-center justify-center p-4 border-2 border-slate-200 rounded-2xl bg-slate-50">
                @if($booking->payment_status !== \App\Enums\PaymentStatus::Unpaid)
                    <!-- Using a public API for QR generation purely for PDF rendering -->
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode($booking->pnr) }}&margin=0" alt="QR Code" class="w-32 h-32 mb-2">
                    <p class="text-xs text-slate-500 font-bold uppercase tracking-widest">للاستخدام الداخلي</p>
                @else
                    <div class="w-32 h-32 mb-2 flex items-center justify-center bg-slate-100 rounded-lg text-slate-400 text-sm font-bold text-center border-2 border-dashed border-slate-300">
                        يصدر بعد الدفع
                    </div>
                @endif
            </div>

            <!-- Terms -->
            <div class="col-span-2 text-sm text-slate-600 leading-relaxed border-r-4 border-slate-200 pr-6">
                <h3 class="font-bold text-slate-800 text-lg mb-2">شروط وأحكام الحجز النقدي:</h3>
                <ul class="list-disc list-inside space-y-1">
                    <li>تعتبر هذه التذكرة إشعار حجز <strong class="text-slate-900">مبدئي غير مؤكد</strong>.</li>
                    <li>يجب دفع المبلغ الإجمالي في أي من فروعنا قبل انتهاء الوقت المحدد لتجنب الإلغاء التلقائي للمقاعد.</li>
                    <li>يرجى إبراز هذا المستند <bdi>(مطبوعاً أو على الهاتف)</bdi> بالإضافة لهوية العميل عند الحضور للدفع.</li>
                    <li>بمجرد إتمام الدفع، سيتم إصدار تذكرة السفر النهائية المؤكدة.</li>
                </ul>
            </div>
        </section>
    </main>

    <footer class="mt-12 text-center text-slate-400 text-sm border-t border-slate-200 pt-6">
        <p>تم إصدار هذه الوثيقة بواسطة نظام Zatara Travel بتاريخ {{ now()->format('Y-m-d H:i') }}</p>
        <p class="mt-1 font-mono text-xs">REF: {{ \Illuminate\Support\Str::uuid() }}</p>
    </footer>
</body>
</html>
