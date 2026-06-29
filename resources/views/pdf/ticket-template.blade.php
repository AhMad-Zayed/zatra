<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boarding Pass - {{ $booking->pnr_code ?? $booking->id }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap');
        body {
            font-family: 'Tajawal', sans-serif;
            background-color: #f3f4f6;
            margin: 0;
            padding: 0;
        }
        .ticket-cutout {
            position: relative;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            margin-bottom: 2rem;
            page-break-inside: avoid;
        }
        .ticket-cutout::before,
        .ticket-cutout::after {
            content: '';
            position: absolute;
            top: 150px;
            width: 24px;
            height: 24px;
            background-color: #f3f4f6;
            border-radius: 50%;
        }
        .ticket-cutout::before { left: -12px; }
        .ticket-cutout::after { right: -12px; }
        
        .dash-line {
            border-top: 2px dashed #e5e7eb;
            margin: 0 1rem;
        }
    </style>
</head>
<body class="p-8">

    <div class="ticket-cutout max-w-4xl mx-auto border border-gray-200">
        
        <!-- Header: Tenant & QR -->
        <div class="bg-gray-900 text-white p-6 flex justify-between items-center h-[150px]">
            <div class="flex items-center gap-4">
                @if($tenant && $tenant->logo)
                    <img src="{{ Storage::url($tenant->logo) }}" alt="Logo" class="w-16 h-16 object-contain bg-white p-1 rounded">
                @endif
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">{{ $tenant->name ?? 'Zatara Travel' }}</h1>
                    <p class="text-sm text-gray-400 mt-1 uppercase tracking-widest">Boarding Pass / بطاقة صعود</p>
                </div>
            </div>
            
            <div class="flex items-center gap-6 text-left" dir="ltr">
                <div class="text-right">
                    <p class="text-xs text-gray-400 uppercase tracking-widest mb-1">Booking Ref (PNR)</p>
                    <p class="text-2xl font-bold font-mono text-yellow-400">{{ $booking->pnr_code ?? str_pad($booking->id, 6, '0', STR_PAD_LEFT) }}</p>
                </div>
                <div class="bg-white p-2 rounded shadow-sm">
                    {!! $qrCode !!}
                </div>
            </div>
        </div>

        <div class="dash-line relative top-[-1px]"></div>

        <!-- Trip Details -->
        <div class="p-8">
            <h2 class="text-lg font-bold text-gray-800 border-b border-gray-100 pb-2 mb-4">تفاصيل الرحلة (Trip Itinerary)</h2>
            <div class="grid grid-cols-3 gap-6 mb-8">
                <div class="bg-blue-50/50 p-4 rounded-lg border border-blue-100">
                    <p class="text-xs text-blue-600 font-bold uppercase tracking-widest mb-1">الانطلاق (Departure)</p>
                    <p class="text-xl font-bold text-gray-900">{{ $trip->start_date?->format('d M Y') ?? 'N/A' }}</p>
                    <p class="text-sm text-gray-600 mt-1">{{ $trip->template->origin ?? 'المحطة الرئيسية' }}</p>
                </div>
                
                <div class="flex flex-col justify-center items-center text-gray-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-blue-400 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                    </svg>
                    <p class="text-xs font-bold text-gray-400">DIRECTION</p>
                </div>

                <div class="bg-green-50/50 p-4 rounded-lg border border-green-100">
                    <p class="text-xs text-green-600 font-bold uppercase tracking-widest mb-1">الوصول (Arrival)</p>
                    <p class="text-xl font-bold text-gray-900">{{ $trip->end_date?->format('d M Y') ?? 'N/A' }}</p>
                    <p class="text-sm text-gray-600 mt-1">{{ $trip->template->destination ?? 'الوجهة السياحية' }}</p>
                </div>
            </div>

            <!-- Addons -->
            @if($booking->addons && $booking->addons->count() > 0)
            <div class="mb-8">
                <p class="text-sm font-bold text-gray-700 mb-2">الإضافات المشتراة (Purchased Add-ons):</p>
                <div class="flex flex-wrap gap-2">
                    @foreach($booking->addons as $addon)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                            {{ $addon->addon_name ?? 'إضافة' }}
                        </span>
                    @endforeach
                </div>
            </div>
            @endif

            <!-- Passenger Manifest -->
            <h2 class="text-lg font-bold text-gray-800 border-b border-gray-100 pb-2 mb-4">قائمة المسافرين (Passenger Manifest)</h2>
            <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
                <table class="w-full text-sm text-right">
                    <thead class="bg-gray-50 text-gray-600 font-bold text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-6 py-3">الاسم (Name)</th>
                            <th class="px-6 py-3">الفئة (Category)</th>
                            <th class="px-6 py-3">نوع الوثيقة (Doc Type)</th>
                            <th class="px-6 py-3">رقم الوثيقة (Doc Number)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 bg-white">
                        @foreach($passengers as $passenger)
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap font-bold text-gray-900">
                                {{ $passenger->first_name }} {{ $passenger->last_name }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-600">
                                {{ $passenger->tripPassengerCategory->name ?? 'مسافر' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-500">
                                {{ $passenger->document_type === 'passport' ? 'جواز سفر' : 'هوية وطنية' }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-gray-900 font-mono" dir="ltr">
                                {{ $passenger->document_number }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer / Legal -->
        <div class="bg-gray-50 p-6 border-t border-gray-200 text-xs text-gray-500 leading-relaxed text-justify">
            <p class="font-bold text-gray-700 mb-2">الشروط والأحكام (Terms & Conditions):</p>
            {!! $tenant->terms_conditions ?? 'تطبق الشروط والأحكام الخاصة بالشركة المنظمة. يعتبر هذا السند تأكيداً لعملية الدفع والقبول بشروط الرحلة. يرجى إبراز هذه التذكرة عند نقطة التجمع. (Standard Terms & Conditions Apply).' !!}
        </div>
    </div>

</body>
</html>
