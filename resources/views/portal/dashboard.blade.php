<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>بوابة العملاء - حجوزاتي</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Tajawal', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            200: '#bbf7d0',
                            300: '#86efac',
                            400: '#4ade80',
                            500: '#22c55e',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d',
                        }
                    }
                }
            }
        }
    </script>
    <!-- Google Fonts: Tajawal -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen flex flex-col justify-between">

    <!-- Header / Navbar -->
    <header class="bg-white border-b border-gray-100 sticky top-0 z-50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo -->
                <div class="flex items-center">
                    <span class="text-2xl font-extrabold text-primary-600 tracking-tight">زاتارا للسياحة</span>
                    <span class="mx-2 text-gray-300">|</span>
                    <span class="text-lg font-semibold text-gray-700">بوابة وثائق السفر</span>
                </div>
                <!-- User details & Logout -->
                <div class="flex items-center gap-4">
                    <div class="text-left hidden md:block">
                        <span class="block text-xs text-gray-400">مرحباً بك</span>
                        <span class="text-sm font-bold text-gray-700">{{ auth()->user()->name }}</span>
                    </div>
                    <form action="{{ route('portal.logout', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}" method="POST">
                        @csrf
                        <button type="submit" class="inline-flex items-center justify-center px-4 h-10 text-sm font-semibold text-red-600 bg-red-50 hover:bg-red-100 rounded-xl transition-colors">
                            تسجيل الخروج
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full text-right">
        
        <div class="mb-8">
            <h1 class="text-2xl font-black text-gray-900">سجل حجوزاتك السياحية</h1>
            <p class="text-sm text-gray-500 mt-1">تتبع حالة الحجوزات وقم بتنزيل تذاكر الطيران وقسائم الفنادق مباشرة</p>
        </div>

        @if($bookings->isEmpty())
            <div class="bg-white rounded-3xl p-12 text-center border border-gray-100 shadow-sm max-w-md mx-auto">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4 text-gray-400">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">لا توجد حجوزات مسجلة</h3>
                <p class="text-gray-500 text-sm mb-4">لم تقم بإتمام أي عمليات حجز تحت هذا الرقم بعد.</p>
                <a href="{{ route('storefront.home', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}" 
                   class="inline-flex items-center justify-center px-6 h-10 text-xs font-bold text-white bg-primary-600 hover:bg-primary-700 rounded-xl transition-colors">
                    استكشف الرحلات المتوفرة
                </a>
            </div>
        @else
            <div class="space-y-8">
                @foreach($bookings as $booking)
                    <section class="bg-white rounded-3xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow overflow-hidden">
                        
                        <!-- Header Bar of Booking Card -->
                        <div class="bg-gray-50 border-b border-gray-100 px-6 py-4 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <div>
                                <span class="text-xs font-semibold text-gray-400">رقم مرجع الحجز</span>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="text-lg font-black text-primary-700">{{ $booking->reference }}</span>
                                    <span class="text-xs bg-gray-200/50 text-gray-600 px-2 py-0.5 rounded-md font-medium" dir="ltr">
                                        {{ $booking->created_at->format('Y-m-d') }}
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Badges -->
                            <div class="flex items-center gap-3">
                                @php
                                    $statusColor = match($booking->status) {
                                        \App\Enums\BookingStatus::PENDING => 'bg-amber-50 text-amber-800 border-amber-100',
                                        \App\Enums\BookingStatus::PARTIAL => 'bg-blue-50 text-blue-800 border-blue-100',
                                        \App\Enums\BookingStatus::PAID => 'bg-emerald-50 text-emerald-800 border-emerald-100',
                                        \App\Enums\BookingStatus::CONFIRMED => 'bg-primary-50 text-primary-800 border-primary-100',
                                        \App\Enums\BookingStatus::CANCELLED => 'bg-red-50 text-red-800 border-red-100',
                                        default => 'bg-gray-50 text-gray-800 border-gray-100',
                                    };
                                    $statusAr = match($booking->status) {
                                        \App\Enums\BookingStatus::PENDING => 'بانتظار الدفع',
                                        \App\Enums\BookingStatus::PARTIAL => 'مدفوع جزئياً',
                                        \App\Enums\BookingStatus::PAID => 'مدفوع بالكامل',
                                        \App\Enums\BookingStatus::CONFIRMED => 'مؤكد ومكتمل',
                                        \App\Enums\BookingStatus::CANCELLED => 'ملغي',
                                        \App\Enums\BookingStatus::COMPLETED => 'منتهي',
                                        default => $booking->status->value,
                                    };
                                @endphp
                                <span class="text-xs font-bold px-3 py-1 rounded-full border {{ $statusColor }}">
                                    {{ $statusAr }}
                                </span>
                            </div>
                        </div>

                        <!-- Card Body -->
                        <div class="p-6 space-y-6">
                            
                            <!-- Trip details -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 border-b border-gray-50 pb-6">
                                <div>
                                    <span class="block text-xs text-gray-400 font-medium mb-1">اسم الرحلة</span>
                                    <span class="font-bold text-gray-800">{{ $booking->tripInstance->tripTemplate->title }}</span>
                                </div>
                                <div>
                                    <span class="block text-xs text-gray-400 font-medium mb-1">تاريخ الذهاب والإياب</span>
                                    <span class="text-sm font-semibold text-gray-700">
                                        {{ $booking->tripInstance->start_date->format('Y-m-d') }} إلى {{ $booking->tripInstance->end_date->format('Y-m-d') }}
                                    </span>
                                </div>
                                <div>
                                    <span class="block text-xs text-gray-400 font-medium mb-1">الرصيد المالي</span>
                                    <div class="text-sm space-y-0.5">
                                        <div class="flex justify-between max-w-[200px]">
                                            <span class="text-gray-500">الإجمالي:</span>
                                            <span class="font-bold text-gray-700">${{ number_format($booking->total_amount, 2) }}</span>
                                        </div>
                                        <div class="flex justify-between max-w-[200px] text-xs">
                                            <span class="text-gray-400">المدفوع:</span>
                                            <span class="font-semibold text-primary-600">${{ number_format($booking->paid_amount, 2) }}</span>
                                        </div>
                                        <div class="flex justify-between max-w-[200px] text-xs">
                                            <span class="text-gray-400">المتبقي:</span>
                                            <span class="font-semibold text-red-500">${{ number_format($booking->remaining_amount, 2) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Passengers List -->
                            <div class="border-b border-gray-50 pb-6">
                                <span class="block text-xs text-gray-400 font-bold mb-3">أسماء المسافرين المسجلين:</span>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                    @foreach($booking->passengers as $passenger)
                                        <div class="bg-gray-50 rounded-xl p-3 border border-gray-100 text-xs">
                                            <div class="font-bold text-gray-800">{{ $passenger->name }}</div>
                                            <div class="text-gray-400 mt-1">جواز سفر: <span class="font-semibold text-gray-600 uppercase">{{ $passenger->passport_number }}</span></div>
                                            @if($passenger->special_requirements)
                                                <div class="text-primary-700 mt-1 italic">ملاحظات: {{ $passenger->special_requirements }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <!-- Optional Extras requested -->
                            @if($booking->flight_details || $booking->hotel_details || $booking->insurance_details || $booking->visa_details)
                                <div class="border-b border-gray-50 pb-6">
                                    <span class="block text-xs text-gray-400 font-bold mb-3">الترتيبات والطلبات الخاصة:</span>
                                    <div class="space-y-3 text-xs leading-relaxed">
                                        @if($booking->flight_details)
                                            <div class="bg-primary-50/20 border border-primary-100/20 rounded-xl p-3">
                                                <strong class="text-primary-800">تفاصيل حجز الطيران:</strong>
                                                <p class="text-gray-600 mt-1">{{ $booking->flight_details }}</p>
                                            </div>
                                        @endif
                                        @if($booking->hotel_details)
                                            <div class="bg-primary-50/20 border border-primary-100/20 rounded-xl p-3">
                                                <strong class="text-primary-800">تفاصيل حجز الفندق:</strong>
                                                <p class="text-gray-600 mt-1">{{ $booking->hotel_details }}</p>
                                            </div>
                                        @endif
                                        @if($booking->insurance_details)
                                            <div class="bg-primary-50/20 border border-primary-100/20 rounded-xl p-3">
                                                <strong class="text-primary-800">تفاصيل تأمين السفر:</strong>
                                                <p class="text-gray-600 mt-1">{{ $booking->insurance_details }}</p>
                                            </div>
                                        @endif
                                        @if($booking->visa_details)
                                            <div class="bg-primary-50/20 border border-primary-100/20 rounded-xl p-3">
                                                <strong class="text-primary-800">تفاصيل تأشيرة السفر (الفيزا):</strong>
                                                <p class="text-gray-600 mt-1">{{ $booking->visa_details }}</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- Documents download section (Request 4) -->
                            <div class="bg-primary-50/30 border border-primary-100/30 rounded-2xl p-6">
                                <h3 class="font-bold text-sm text-primary-900 mb-3 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    <span>وثائق ومستندات السفر</span>
                                </h3>

                                @if ($booking->status === \App\Enums\BookingStatus::CONFIRMED || !$booking->getMedia('vouchers_and_tickets')->isEmpty())
                                    @php $mediaItems = $booking->getMedia('vouchers_and_tickets'); @endphp
                                    @if ($mediaItems->isEmpty())
                                        <div class="text-xs text-primary-800 leading-relaxed">
                                            👍 الحجز مؤكد حالياً! يقوم القائمون على الرحلة برفع ملفاتك وتذاكرك الآن. يرجى مراجعة الصفحة بعد قليل.
                                        </div>
                                    @else
                                        <div class="space-y-3">
                                            <p class="text-xs text-gray-500 mb-3">يمكنك تحميل مستنداتك وتذاكرك مباشرة بصيغة PDF أو صور:</p>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                                @foreach($mediaItems as $media)
                                                    <a href="{{ $media->getUrl() }}" download target="_blank"
                                                       class="flex items-center justify-between p-3 bg-white border border-gray-100 rounded-xl hover:border-primary-500 transition-colors shadow-sm">
                                                        <div class="flex items-center gap-3">
                                                            <div class="w-8 h-8 rounded-lg bg-red-50 text-red-600 flex items-center justify-center font-bold text-xs uppercase">
                                                                {{ $media->extension }}
                                                            </div>
                                                            <div class="text-right">
                                                                <span class="block text-xs font-bold text-gray-700 line-clamp-1">{{ $media->file_name }}</span>
                                                                <span class="block text-[10px] text-gray-400">{{ number_format($media->size / 1024, 1) }} KB</span>
                                                            </div>
                                                        </div>
                                                        <span class="text-xs font-bold text-primary-600 hover:text-primary-700">تحميل الآن ↓</span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @else
                                    <div class="bg-amber-50/50 border border-amber-100/50 rounded-xl p-4 text-xs text-amber-800 leading-relaxed">
                                        🔒 سيتم إتاحة تذاكر الطيران، قسائم الفنادق ووثائق التأمين للتحميل مباشرة من هنا فور سداد كامل المبلغ المطلوب وتأكيد الحجز من قبل إدارة الوكالة.
                                    </div>
                                @endif
                            </div>

                        </div>
                    </section>
                @endforeach
            </div>
        @endif

    </main>

    <!-- Footer -->
    <footer class="bg-gray-950 text-gray-400 py-6 text-center text-xs border-t border-gray-900 mt-12">
        <p>© {{ date('Y') }} زاتارا للسياحة - بوابة وثائق السفر.</p>
    </footer>

</body>
</html>
