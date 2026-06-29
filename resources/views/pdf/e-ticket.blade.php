<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تذكرة الصعود - {{ $booking->pnr }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
            background-color: #f3f4f6; /* Gray 100 */
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    </style>
</head>
<body class="p-8">
    <div class="max-w-4xl mx-auto bg-white border-2 border-slate-800 rounded-xl overflow-hidden shadow-2xl">
        <!-- Header -->
        <div class="bg-slate-900 text-white p-6 flex justify-between items-center border-b-4 border-amber-500">
            <div>
                <h1 class="text-3xl font-bold text-amber-500 tracking-wider">{{ $tenant->name }}</h1>
                <p class="text-sm text-slate-300 mt-1">تذكرة سفر إلكترونية عتيقة (E-Ticket)</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">رمز الحجز (PNR)</p>
                <p class="text-2xl font-mono font-bold bg-white text-slate-900 px-4 py-1 rounded">{{ $booking->pnr }}</p>
            </div>
        </div>

        <!-- Trip Details Grid -->
        <div class="p-8 border-b border-slate-200">
            <h2 class="text-xl font-bold text-slate-800 mb-6 flex items-center">
                <svg class="w-6 h-6 ml-2 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                تفاصيل الرحلة
            </h2>
            
            <div class="grid grid-cols-2 gap-8">
                <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <p class="text-sm text-slate-500 mb-1">الباقة / الوجهة</p>
                    <p class="text-lg font-bold text-slate-900">{{ $booking->tripInstance->template->title ?? 'N/A' }}</p>
                </div>
                
                <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <p class="text-sm text-slate-500 mb-1">المدة</p>
                    <p class="text-lg font-bold text-slate-900">{{ $booking->tripInstance->template->nights ?? 0 }} ليالي / {{ $booking->tripInstance->template->days ?? 0 }} أيام</p>
                </div>

                <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <p class="text-sm text-slate-500 mb-1">تاريخ المغادرة</p>
                    <p class="text-lg font-bold text-slate-900" dir="ltr">
                        {{ \Carbon\Carbon::parse($booking->tripInstance->start_date)->format('d M Y') }}
                    </p>
                </div>

                <div class="bg-slate-50 p-4 rounded-lg border border-slate-100">
                    <p class="text-sm text-slate-500 mb-1">تاريخ العودة</p>
                    <p class="text-lg font-bold text-slate-900" dir="ltr">
                        {{ \Carbon\Carbon::parse($booking->tripInstance->end_date)->format('d M Y') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Passengers & QR Grid -->
        <div class="flex">
            <!-- Passenger Manifest -->
            <div class="w-3/4 p-8 border-l border-slate-200">
                <h2 class="text-xl font-bold text-slate-800 mb-6 flex items-center">
                    <svg class="w-6 h-6 ml-2 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    بيانات المسافرين
                </h2>
                
                <table class="w-full text-right">
                    <thead>
                        <tr class="text-slate-500 border-b border-slate-200 text-sm">
                            <th class="pb-3 font-semibold">الاسم الأول</th>
                            <th class="pb-3 font-semibold">اسم العائلة</th>
                            <th class="pb-3 font-semibold">نوع الوثيقة</th>
                            <th class="pb-3 font-semibold">رقم الوثيقة</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($booking->passengers as $passenger)
                        <tr class="border-b border-slate-100">
                            <td class="py-4 text-slate-900 font-medium">{{ $passenger->first_name ?? $passenger->name }}</td>
                            <td class="py-4 text-slate-900">{{ $passenger->last_name ?? '-' }}</td>
                            <td class="py-4 text-slate-600">{{ ucfirst($passenger->document_type ?? 'Passport') }}</td>
                            <td class="py-4 text-slate-900 font-mono">{{ $passenger->document_number ?? '-' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- QR Code -->
            <div class="w-1/4 p-8 flex flex-col items-center justify-center bg-slate-50">
                <p class="text-xs text-slate-500 mb-4 text-center">امسح الكود للتحقق من التذكرة</p>
                <!-- Render a fallback QR visually using a simple API or SVG generator -->
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={{ urlencode(url('/booking/verify/' . $booking->pnr)) }}" alt="QR Code" class="w-32 h-32 border-4 border-white shadow-sm rounded-lg">
                <p class="mt-4 font-mono text-sm text-slate-800 font-bold">{{ $booking->pnr }}</p>
            </div>
        </div>

        <!-- Footer -->
        <div class="bg-slate-100 p-6 text-center text-xs text-slate-500">
            <p class="mb-2 text-slate-700 font-bold">الشروط والأحكام</p>
            <p>{{ $tenant->terms_conditions ?? 'هذه التذكرة إلكترونية ومعتمدة ولا تحتاج لختم. يرجى التواجد قبل موعد المغادرة بوقت كافٍ. تطبق الشروط والأحكام الخاصة بالوكالة المنظمة للرحلة.' }}</p>
        </div>
    </div>
</body>
</html>
