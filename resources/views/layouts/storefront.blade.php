<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إتمام الحجز - زاتارا للسياحة</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <!-- Google Fonts: Tajawal -->
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Tajawal', sans-serif;
        }
    </style>
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen flex flex-col">

    <!-- Header / Navbar -->
    <header class="bg-white/80 backdrop-blur-xl sticky top-0 z-50 shadow-sm relative border-b-0">
        <div class="absolute bottom-0 left-0 right-0 h-[1px] bg-gradient-to-r from-transparent via-zatara-blue/20 to-transparent"></div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo -->
                <div class="flex items-center gap-3">
                    @php $tenant = app(\App\Models\Tenant::class); @endphp
                    <a href="{{ route('storefront.catalog', ['tenant' => $tenant->slug ?? 'default']) }}" class="flex items-center">
                        @if($tenant && $tenant->hasMedia('logo'))
                            <img src="{{ $tenant->getFirstMediaUrl('logo') }}" alt="{{ $tenant->name }}" class="h-10">
                        @else
                            <span class="text-2xl font-extrabold text-zatara-blue tracking-tight">{{ $tenant->name ?? 'زاتارا للسياحة' }}</span>
                        @endif
                    </a>
                </div>
                <!-- Navigation -->
                <div class="hidden md:flex items-center gap-6">
                    @if($tenant)
                        <a href="{{ route('storefront.catalog', ['tenant' => $tenant->slug]) }}" 
                           class="text-sm font-bold text-slate-600 hover:text-zatara-gold transition-colors">
                            الرئيسية
                        </a>
                        <a href="{{ route('storefront.my-bookings', ['tenant' => $tenant->slug]) }}" 
                           class="text-sm font-bold text-slate-600 hover:text-zatara-gold transition-colors">
                            رحلاتي
                        </a>
                        <a href="#" 
                           class="text-sm font-bold text-slate-600 hover:text-zatara-gold transition-colors">
                            تواصل معنا
                        </a>
                    @endif
                </div>
                <!-- Auth State -->
                <div class="flex items-center gap-4">
                    @auth('customer')
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold text-zatara-blue">مرحباً {{ explode(' ', auth('customer')->user()->name)[0] }}</span>
                            <div class="w-10 h-10 rounded-full bg-zatara-gold/20 flex items-center justify-center text-zatara-gold font-bold">
                                {{ mb_substr(auth('customer')->user()->name, 0, 1) }}
                            </div>
                        </div>
                    @else
                        @if($tenant)
                            <a href="{{ route('portal.login', ['tenant' => $tenant->slug]) }}" class="btn-primary py-2 px-6 text-sm">
                                {{ __('تسجيل الدخول') }}
                            </a>
                        @endif
                    @endauth
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="flex-grow">
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="bg-gray-950 text-gray-400 py-8 border-t border-gray-900 text-center text-xs mt-12">
        <div class="max-w-7xl mx-auto px-4">
            <p class="mb-2">© {{ date('Y') }} زاتارا للسياحة - نظام حجز الرحلات المتكامل.</p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
