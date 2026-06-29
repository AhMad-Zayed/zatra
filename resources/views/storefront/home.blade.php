<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رحلات - {{ $tenant->name }}</title>
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
<body class="bg-gray-50 text-gray-900 min-h-screen flex flex-col">

    <!-- Header / Navbar -->
    <header class="bg-white border-b border-gray-100 sticky top-0 z-50 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo -->
                <div class="flex items-center">
                    <span class="text-2xl font-extrabold text-primary-600 tracking-tight">زاتارا للسياحة</span>
                    <span class="mx-2 text-gray-300">|</span>
                    <span class="text-lg font-semibold text-gray-700">{{ $tenant->name }}</span>
                </div>
                <!-- Navigation -->
                <div class="flex items-center gap-4">
                    <a href="{{ route('portal.login', ['tenant_slug' => \Illuminate\Support\Str::slug($tenant->name)]) }}" 
                       class="inline-flex items-center justify-center px-4 h-10 text-sm font-medium text-primary-700 bg-primary-50 rounded-xl hover:bg-primary-100 transition-colors">
                        بوابة العملاء (تنزيل الوثائق)
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="relative bg-gradient-to-br from-primary-900 to-primary-800 text-white py-20 px-4 overflow-hidden">
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(34,197,94,0.15),transparent_50%)]"></div>
        <div class="max-w-5xl mx-auto text-center relative z-10">
            <h1 class="text-4xl md:text-5xl font-black mb-6 leading-tight">اكتشف العالم معنا مع أفضل تنظيم للرحلات</h1>
            <p class="text-lg md:text-xl text-primary-100 max-w-2xl mx-auto mb-8 font-light">
                احجز رحلتك القادمة الآن بخطوات بسيطة وبأقل مجهود. نوفر لك أفضل الرحلات السياحية الداخلية والخارجية.
            </p>
        </div>
    </section>

    <!-- Trip List Section -->
    <main class="flex-grow max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 w-full">
        <div class="mb-10 text-right">
            <h2 class="text-2xl font-bold text-gray-900">الرحلات المتاحة حالياً</h2>
            <p class="text-sm text-gray-500 mt-1">اختر رحلتك وقم بالتسجيل الفوري ببضع نقرات</p>
        </div>

        @if($tripInstances->isEmpty())
            <div class="bg-white rounded-2xl p-12 text-center border border-gray-100 shadow-sm max-w-xl mx-auto">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4 text-gray-400">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-800 mb-2">لا تتوفر رحلات حالياً</h3>
                <p class="text-gray-500 text-sm">نحن نعمل على جدولة رحلات جديدة قريباً. يرجى مراجعة الصفحة لاحقاً.</p>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                @foreach($tripInstances as $instance)
                    <article class="bg-white rounded-3xl overflow-hidden border border-gray-100 hover:border-primary-100 shadow-sm hover:shadow-xl transition-all duration-300 flex flex-col group">
                        <!-- Card Header Gradient -->
                        <div class="h-32 bg-gradient-to-l from-primary-600 to-primary-800 p-6 flex flex-col justify-end text-white relative">
                            <span class="absolute top-4 left-4 bg-white/20 backdrop-blur-md px-3 py-1 rounded-full text-xs font-semibold">
                                {{ $instance->available_seats }} مقاعد متبقية
                            </span>
                            <h3 class="text-xl font-bold tracking-tight line-clamp-1 group-hover:text-primary-100 transition-colors">
                                {{ $instance->tripTemplate->title }}
                            </h3>
                        </div>

                        <!-- Card Body -->
                        <div class="p-6 flex-grow flex flex-col justify-between">
                            <!-- Trip Details -->
                            <div class="space-y-4 mb-6">
                                <p class="text-gray-600 text-sm line-clamp-2 leading-relaxed">
                                    {{ $instance->tripTemplate->description ?? 'استمتع برحلة رائعة ومميزة بتنظيم متكامل وخدمات فاخرة تلبي تطلعاتك.' }}
                                </p>
                                
                                <div class="grid grid-cols-2 gap-4 border-t border-gray-50 pt-4 text-xs text-gray-500">
                                    <div>
                                        <span class="block text-gray-400 font-medium">تاريخ الذهاب</span>
                                        <span class="font-bold text-gray-700">{{ $instance->start_date->format('Y-m-d') }}</span>
                                    </div>
                                    <div>
                                        <span class="block text-gray-400 font-medium">تاريخ الإياب</span>
                                        <span class="font-bold text-gray-700">{{ $instance->end_date->format('Y-m-d') }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Pricing & Action -->
                            <div class="flex items-center justify-between pt-4 border-t border-gray-50">
                                <div class="text-right">
                                    <span class="block text-xs text-gray-400 font-medium">سعر الرحلة</span>
                                    <span class="text-2xl font-black text-primary-700">
                                        ${{ number_format($instance->tripTemplate->base_price, 2) }}
                                    </span>
                                </div>
                                @if($instance->remaining_seats > 0)
                                    <a href="{{ route('storefront.checkout', ['tenant' => $tenant->slug, 'tripInstance' => $instance->id]) }}" 
                                       class="inline-flex items-center justify-center px-6 h-12 text-sm font-bold text-white bg-primary-600 rounded-2xl hover:bg-primary-700 transition-colors">
                                        احجز الآن
                                    </a>
                                @else
                                    <span class="inline-flex items-center justify-center px-6 h-12 text-sm font-bold text-slate-500 bg-slate-200 rounded-2xl cursor-not-allowed">
                                        مكتملة العدد
                                    </span>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </main>

    <!-- Footer -->
    <footer class="bg-gray-950 text-gray-400 py-8 border-t border-gray-900 mt-12 text-center text-xs">
        <div class="max-w-7xl mx-auto px-4">
            <p class="mb-2">© {{ date('Y') }} زاتارا للسياحة - نظام حجز الرحلات المتكامل.</p>
            <p class="text-gray-600">جميع الحقوق محفوظة للوكالة السياحية الشريكة.</p>
        </div>
    </footer>

</body>
</html>
